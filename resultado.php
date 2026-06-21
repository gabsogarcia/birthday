<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

function respond(array $body, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function kieStorageFile(string $taskId): string
{
    return __DIR__ . '/data/kie/' . hash('sha256', $taskId) . '.json';
}

function ensureKieStorage(): bool
{
    $directory = __DIR__ . '/data/kie';
    return is_dir($directory) || @mkdir($directory, 0700, true);
}

function currentBaseUrl(): string
{
    $forwardedProto = strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $isHttps = $forwardedProto === 'https'
        || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
    $scheme = $isHttps ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $directory = rtrim(str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/'))), '/');
    return $scheme . '://' . $host . ($directory === '' ? '' : $directory);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['health'] ?? '') === '1') {
    respond([
        'ok' => true,
        'phpVersion' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'kieConfigured' => strpos(KIE_API_KEY, 'COLE_AQUI_') !== 0,
        'callbackUrl' => currentBaseUrl() . '/resultado.php?action=kie-callback',
        'storageWritable' => ensureKieStorage(),
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_GET['action'] ?? '') === 'kie-callback') {
    $payload = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($payload)) {
        respond(['error' => 'Invalid callback JSON'], 400);
    }

    $callbackData = is_array($payload['data'] ?? null) ? $payload['data'] : [];
    $taskId = trim((string)(
        $callbackData['task_id']
        ?? $callbackData['taskId']
        ?? $payload['task_id']
        ?? $payload['taskId']
        ?? ''
    ));
    if ($taskId === '') {
        respond(['error' => 'Callback taskId is missing'], 422);
    }

    $tracks = is_array($callbackData['data'] ?? null) ? $callbackData['data'] : [];
    $firstTrack = isset($tracks[0]) && is_array($tracks[0]) ? $tracks[0] : [];
    $audioUrl = $firstTrack['audio_url'] ?? $firstTrack['audioUrl']
        ?? $firstTrack['stream_audio_url'] ?? $firstTrack['streamAudioUrl'] ?? null;
    $callbackType = (string)($callbackData['callbackType'] ?? '');

    if (ensureKieStorage()) {
        file_put_contents(
            kieStorageFile($taskId),
            json_encode([
                'taskId' => $taskId,
                'callbackType' => $callbackType,
                'audioUrl' => $audioUrl,
                'payload' => $payload,
                'updatedAt' => gmdate(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    respond(['received' => true]);
}

if (!function_exists('curl_init')) {
    respond(['error' => 'PHP cURL extension is required'], 500);
}
if (strpos(KIE_API_KEY, 'COLE_AQUI_') === 0) {
    respond(['error' => 'KIE_API_KEY is not configured'], 503);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        respond(['error' => 'Invalid JSON body'], 400);
    }

    $name = trim((string)($input['name'] ?? ''));
    $age = (int)($input['age'] ?? 0);
    $lyrics = trim((string)($input['lyrics'] ?? ''));
    $email = filter_var((string)($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['sessao'] ?? '')) ?: '';

    if ($name === '' || $age < 1 || $age > 120 || $lyrics === '' || !$email || $sessionId === '') {
        respond(['error' => 'Invalid generation data'], 422);
    }

    $curl = curl_init(KIE_API_URL);
    $callbackUrl = currentBaseUrl() . '/resultado.php?action=kie-callback';
    if (strpos($callbackUrl, 'https://') !== 0) {
        respond(['error' => 'Kie callback URL must use HTTPS: ' . $callbackUrl], 500);
    }
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . KIE_API_KEY,
            'Content-Type: application/json',
        ],
        CURLOPT_POSTFIELDS => json_encode([
            'prompt' => $lyrics,
            'customMode' => true,
            'instrumental' => false,
            'model' => KIE_MODEL,
            'callBackUrl' => $callbackUrl,
            'style' => KIE_MUSIC_STYLE,
            'title' => substr('Happy Birthday ' . $name, 0, 80),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ]);

    $rawResponse = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($rawResponse === false) {
        respond(['error' => 'Could not contact Kie.ai: ' . $curlError], 502);
    }
    $kieResponse = json_decode($rawResponse, true);
    $taskId = $kieResponse['data']['taskId'] ?? null;
    $kieCode = isset($kieResponse['code']) ? (int)$kieResponse['code'] : 0;
    if ($httpStatus < 200 || $httpStatus >= 300 || $kieCode !== 200 || !$taskId) {
        error_log('[KIE] Generate failed HTTP ' . $httpStatus . ': ' . $rawResponse);
        respond([
            'error' => $kieResponse['msg'] ?? 'Kie.ai did not return a taskId',
            'kieCode' => $kieCode,
        ], 502);
    }
    respond(['taskId' => $taskId]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    respond(['error' => 'Method not allowed'], 405);
}

$taskId = trim((string)($_GET['taskId'] ?? ''));
if ($taskId === '' || !preg_match('/^[a-zA-Z0-9_-]{8,128}$/', $taskId)) {
    respond(['error' => 'Invalid taskId'], 422);
}

$storedFile = kieStorageFile($taskId);
if (is_file($storedFile)) {
    $stored = json_decode(file_get_contents($storedFile) ?: '', true);
    if (is_array($stored) && !empty($stored['audioUrl'])) {
        respond([
            'status' => 'SUCCESS',
            'audioUrl' => $stored['audioUrl'],
        ]);
    }
}

$curl = curl_init(KIE_STATUS_URL . '?taskId=' . rawurlencode($taskId));
curl_setopt_array($curl, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . KIE_API_KEY],
]);
$rawResponse = curl_exec($curl);
$curlError = curl_error($curl);
$httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
curl_close($curl);

if ($rawResponse === false) {
    respond(['error' => 'Could not contact Kie.ai: ' . $curlError], 502);
}
$kieResponse = json_decode($rawResponse, true);
if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($kieResponse)
    || (isset($kieResponse['code']) && (int)$kieResponse['code'] !== 200)) {
    error_log('[KIE] Status failed HTTP ' . $httpStatus . ': ' . $rawResponse);
    respond(['error' => 'Could not read generation status'], 502);
}

$data = $kieResponse['data'] ?? [];
$status = (string)($data['status'] ?? 'PENDING');
$firstTrack = $data['response']['sunoData'][0] ?? [];
$failed = ['CREATE_TASK_FAILED', 'GENERATE_AUDIO_FAILED', 'CALLBACK_EXCEPTION', 'SENSITIVE_WORD_ERROR'];

if (in_array($status, $failed, true)) {
    respond(['status' => $status, 'error' => $data['errorMessage'] ?? 'Music generation failed'], 502);
}

$audioUrl = $firstTrack['audioUrl'] ?? $firstTrack['streamAudioUrl'] ?? null;
respond(array_filter([
    'status' => $status,
    'audioUrl' => $audioUrl,
], static fn($value) => $value !== null));
