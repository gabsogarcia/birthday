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
    if ($httpStatus < 200 || $httpStatus >= 300 || !$taskId) {
        respond(['error' => $kieResponse['msg'] ?? 'Kie.ai did not return a taskId'], 502);
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
if ($httpStatus < 200 || $httpStatus >= 300 || !is_array($kieResponse)) {
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
