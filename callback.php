<?php
declare(strict_types=1);

ini_set('display_errors', '0');
error_reporting(E_ALL);
ob_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/config.php';

function respond(array $body, int $status = 200): void
{
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if (!$error || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        return;
    }
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Payment server configuration error. Check the PHP error log.']);
});

function verifyStripeSignature(string $payload, string $header, string $secret, int $tolerance = 300): bool
{
    $timestamp = null;
    $signatures = [];
    foreach (explode(',', $header) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        if ($key === 't') {
            $timestamp = ctype_digit($value) ? (int)$value : null;
        } elseif ($key === 'v1' && $value !== '') {
            $signatures[] = $value;
        }
    }
    if ($timestamp === null || !$signatures || abs(time() - $timestamp) > $tolerance) {
        return false;
    }
    $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
    foreach ($signatures as $signature) {
        if (hash_equals($expected, $signature)) {
            return true;
        }
    }
    return false;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['health'] ?? '') === '1') {
    respond([
        'ok' => true,
        'phpVersion' => PHP_VERSION,
        'curl' => function_exists('curl_init'),
        'stripeConfigured' => strpos(STRIPE_SECRET_KEY, 'COLE_AQUI_') !== 0,
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'public-config') {
    if (strpos(STRIPE_PUBLISHABLE_KEY, 'COLE_AQUI_') === 0) {
        respond(['error' => 'STRIPE_PUBLISHABLE_KEY is not configured'], 503);
    }
    respond(['stripePublishableKey' => STRIPE_PUBLISHABLE_KEY]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(['error' => 'Method not allowed'], 405);
}

if (($_GET['action'] ?? '') === 'create-payment-intent') {
    if (!function_exists('curl_init')) {
        respond(['error' => 'PHP cURL extension is required'], 500);
    }
    if (strpos(STRIPE_SECRET_KEY, 'COLE_AQUI_') === 0) {
        respond(['error' => 'STRIPE_SECRET_KEY is not configured'], 503);
    }

    $input = json_decode(file_get_contents('php://input') ?: '', true);
    if (!is_array($input)) {
        respond(['error' => 'Invalid JSON body'], 400);
    }

    $priceByCurrency = [
        'zar' => 9700, 'php' => 19700, 'usd' => 900,
        'cad' => 900, 'gbp' => 900, 'aud' => 900,
    ];
    $bumpPercentages = ['style' => 0.30, 'video' => 0.50];
    $currency = strtolower(trim((string)($input['currency'] ?? '')));
    $amount = (int)($input['amount'] ?? 0);
    $email = filter_var((string)($input['email'] ?? ''), FILTER_VALIDATE_EMAIL);
    $name = trim((string)($input['name'] ?? ''));
    $sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($input['sessao'] ?? '')) ?: '';
    $bumps = isset($input['bumps']) && is_array($input['bumps']) ? array_unique($input['bumps']) : [];

    if (!isset($priceByCurrency[$currency])) {
        respond(['error' => 'Unsupported currency'], 422);
    }
    if (!$email || $name === '' || $sessionId === '') {
        respond(['error' => 'Valid email, name and session are required'], 422);
    }

    $expectedAmount = $priceByCurrency[$currency];
    $validBumps = [];
    foreach ($bumps as $bump) {
        if (isset($bumpPercentages[$bump])) {
            $expectedAmount += (int)round($priceByCurrency[$currency] * $bumpPercentages[$bump]);
            $validBumps[] = $bump;
        }
    }
    if ($amount !== $expectedAmount) {
        respond(['error' => 'Order total does not match server pricing'], 422);
    }

    $curl = curl_init('https://api.stripe.com/v1/payment_intents');
    curl_setopt_array($curl, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_USERPWD => STRIPE_SECRET_KEY . ':',
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'Idempotency-Key: birthday-' . hash('sha256', $sessionId . '|' . $currency . '|' . $expectedAmount),
        ],
        CURLOPT_POSTFIELDS => http_build_query([
            'amount' => $expectedAmount,
            'currency' => $currency,
            'receipt_email' => $email,
            'payment_method_types[0]' => 'card',
            'metadata[sessao]' => $sessionId,
            'metadata[name]' => substr($name, 0, 100),
            'metadata[bumps]' => implode(',', $validBumps),
        ]),
    ]);

    $rawResponse = curl_exec($curl);
    $curlError = curl_error($curl);
    $httpStatus = (int)curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);

    if ($rawResponse === false) {
        respond(['error' => 'Could not contact Stripe: ' . $curlError], 502);
    }

    $stripeResponse = json_decode($rawResponse, true);
    $clientSecret = $stripeResponse['client_secret'] ?? null;
    if ($httpStatus < 200 || $httpStatus >= 300 || !$clientSecret) {
        error_log('[STRIPE] PaymentIntent failed: HTTP ' . $httpStatus . ' ' . $rawResponse);
        respond(['error' => $stripeResponse['error']['message'] ?? 'Could not create PaymentIntent'], 502);
    }
    respond(['clientSecret' => $clientSecret]);
}

if (strpos(STRIPE_WEBHOOK_SECRET, 'COLE_AQUI_') === 0) {
    respond(['error' => 'STRIPE_WEBHOOK_SECRET is not configured'], 503);
}

$payload = file_get_contents('php://input') ?: '';
$signature = (string)($_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '');
if (!verifyStripeSignature($payload, $signature, STRIPE_WEBHOOK_SECRET)) {
    respond(['error' => 'Invalid Stripe signature'], 400);
}

$event = json_decode($payload, true);
if (!is_array($event) || empty($event['id']) || empty($event['type'])) {
    respond(['error' => 'Invalid Stripe event'], 400);
}
if ($event['type'] !== 'payment_intent.succeeded') {
    respond(['received' => true]);
}

$intent = $event['data']['object'] ?? [];
$sessionId = preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($intent['metadata']['sessao'] ?? '')) ?: '';
if ($sessionId !== '') {
    $storageDir = __DIR__ . '/data/payments';
    if (!is_dir($storageDir)) {
        @mkdir($storageDir, 0700, true);
    }
    if (is_dir($storageDir)) {
        file_put_contents(
            $storageDir . '/' . hash('sha256', $sessionId) . '.json',
            json_encode([
                'eventId' => $event['id'],
                'paymentIntentId' => $intent['id'] ?? '',
                'sessao' => $sessionId,
                'name' => $intent['metadata']['name'] ?? '',
                'amount' => $intent['amount_received'] ?? $intent['amount'] ?? 0,
                'currency' => $intent['currency'] ?? '',
                'paidAt' => gmdate(DATE_ATOM),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}

respond(['received' => true]);
