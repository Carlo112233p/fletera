<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    echo json_encode(['ok' => true, 'service' => 'paps-form']);
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method']);
    exit;
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 20000) {
    http_response_code(413);
    echo json_encode(['ok' => false, 'error' => 'too_large']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

// Honeypot anti-spam
if (!empty($data['website'])) {
    echo json_encode(['ok' => true]);
    exit;
}

function clean(mixed $value, int $max = 2000): string {
    $text = trim((string) $value);
    $text = str_replace(["\r", "\n", "\0"], ' ', $text);
    return mb_substr($text, 0, $max);
}

// Destino de los correos
$to = 'admin@papstexaslogistics.com';

function send_via_formsubmit(string $to, array $payload, string $origin): ?array {
    if (!function_exists('curl_init')) {
        return null;
    }

    $ch = curl_init('https://formsubmit.co/ajax/' . rawurlencode($to));
    if ($ch === false) {
        return null;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Origin: ' . $origin,
            'Referer: ' . $origin . '/',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $fsRaw = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    unset($ch);

    if (!is_string($fsRaw) || $fsRaw === '') {
        return ['success' => false, 'http' => $httpCode];
    }

    $fs = json_decode($fsRaw, true);
    return is_array($fs) ? $fs : ['success' => false, 'http' => $httpCode];
}

$kind  = clean($data['form'] ?? '', 20);
$reply = '';
$fsPayload = [
    '_captcha'  => 'false',
    '_template' => 'table',
];

if ($kind === 'quote') {
    $pickup    = clean($data['pickup_zip']   ?? '', 16);
    $dropoff   = clean($data['dropoff_zip']  ?? '', 16);
    $commodity = clean($data['commodity']    ?? '', 80);
    $weight    = clean($data['weight_lbs']   ?? '', 24);
    $date      = clean($data['pickup_date']  ?? '', 32);
    $contact   = clean($data['contact']      ?? '', 120);
    $notes     = clean($data['notes']        ?? '', 2000);

    if ($pickup === '' || $dropoff === '' || $commodity === '' || $weight === '' || $date === '' || $contact === '') {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'missing']);
        exit;
    }

    if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
        $reply = $contact;
    }

    $fsPayload['_subject']    = 'Quote request — PAPS Logistics';
    $fsPayload['pickup_zip']  = $pickup;
    $fsPayload['dropoff_zip'] = $dropoff;
    $fsPayload['commodity']   = $commodity;
    $fsPayload['weight_lbs']  = $weight;
    $fsPayload['pickup_date'] = $date;
    $fsPayload['contact']     = $contact;
    $fsPayload['notes']       = $notes;

} elseif ($kind === 'driver') {
    $name       = clean($data['name']       ?? '', 120);
    $phone      = clean($data['phone']      ?? '', 40);
    $email      = clean($data['email']      ?? '', 160);
    $experience = clean($data['experience'] ?? '', 40);

    if ($name === '' || $phone === '' || $email === '' || $experience === ''
        || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'missing']);
        exit;
    }

    $reply = $email;

    $fsPayload['_subject']   = 'Driver application — PAPS Logistics';
    $fsPayload['name']       = $name;
    $fsPayload['phone']      = $phone;
    $fsPayload['email']      = $email;
    $fsPayload['experience'] = $experience;

} else {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'unknown_form']);
    exit;
}

if ($reply !== '') {
    $fsPayload['_replyto'] = $reply;
}

// Rate-limit por IP
$ip     = $_SERVER['REMOTE_ADDR'] ?? '0';
$bucket = sys_get_temp_dir() . '/paps-form-' . hash('sha256', $ip);
if (is_file($bucket) && (time() - (int) filemtime($bucket)) < 20) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'slow']);
    exit;
}

// Origin para que FormSubmit acepte la petición
$host   = $_SERVER['HTTP_HOST'] ?? 'papstexaslogistics.com';
$host   = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host) ?: 'papstexaslogistics.com';
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'https';
$origin = $scheme . '://' . $host;

$fs = send_via_formsubmit($to, $fsPayload, $origin);
if (is_array($fs) && ($fs['success'] === true || $fs['success'] === 'true')) {
    @touch($bucket);
    echo json_encode(['ok' => true, 'via' => 'formsubmit']);
    exit;
}

http_response_code(500);
echo json_encode([
    'ok'    => false,
    'error' => 'send_failed',
    'hint'  => 'FormSubmit rejected the request. Confirm activation email sent to ' . $to,
]);
