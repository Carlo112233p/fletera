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

if (!empty($data['website'])) {
  echo json_encode(['ok' => true]);
  exit;
}

function clean(mixed $value, int $max = 2000): string {
  $text = trim((string) $value);
  $text = str_replace(["\r", "\n", "\0"], ' ', $text);
  if (function_exists('mb_substr')) {
    return mb_substr($text, 0, $max);
  }
  return substr($text, 0, $max);
}

function send_via_formsubmit(string $to, array $payload): ?array {
  if (!function_exists('curl_init')) {
    return null;
  }

  $ch = curl_init('https://formsubmit.co/ajax/' . rawurlencode($to));
  if ($ch === false) {
    return null;
  }

  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_CONNECTTIMEOUT => 10,
  ]);

  $fsRaw = curl_exec($ch);
  $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
  curl_close($ch);

  if (!is_string($fsRaw) || $fsRaw === '') {
    return ['success' => false, 'http' => $httpCode];
  }

  $fs = json_decode($fsRaw, true);
  return is_array($fs) ? $fs : ['success' => false, 'http' => $httpCode];
}

$kind = clean($data['form'] ?? '', 20);
$to = 'admin@papstexaslogistics.com';
$reply = '';
$fsPayload = [
  '_captcha' => 'false',
  '_template' => 'table',
];

if ($kind === 'quote') {
  $pickup    = clean($data['pickup_zip'] ?? '', 16);
  $dropoff   = clean($data['dropoff_zip'] ?? '', 16);
  $commodity = clean($data['commodity'] ?? '', 80);
  $weight    = clean($data['weight_lbs'] ?? '', 24);
  $date      = clean($data['pickup_date'] ?? '', 32);
  $contact   = clean($data['contact'] ?? '', 120);
  $notes     = clean($data['notes'] ?? '', 2000);

  if ($pickup === '' || $dropoff === '' || $commodity === '' || $weight === '' || $date === '' || $contact === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing']);
    exit;
  }

  $subject = 'Quote request — PAPS Logistics';
  $body = "New quote request from papstexaslogistics.com\n\n"
    . "Pickup ZIP: {$pickup}\n"
    . "Drop-off ZIP: {$dropoff}\n"
    . "Commodity: {$commodity}\n"
    . "Weight (lbs): {$weight}\n"
    . "Pickup date: {$date}\n"
    . "Phone or email: {$contact}\n"
    . "Notes: {$notes}\n";

  if (filter_var($contact, FILTER_VALIDATE_EMAIL)) {
    $reply = $contact;
  }

  $fsPayload['_subject'] = $subject;
  $fsPayload['pickup_zip'] = $pickup;
  $fsPayload['dropoff_zip'] = $dropoff;
  $fsPayload['commodity'] = $commodity;
  $fsPayload['weight_lbs'] = $weight;
  $fsPayload['pickup_date'] = $date;
  $fsPayload['contact'] = $contact;
  $fsPayload['notes'] = $notes;
} elseif ($kind === 'driver') {
  $name       = clean($data['name'] ?? '', 120);
  $phone      = clean($data['phone'] ?? '', 40);
  $email      = clean($data['email'] ?? '', 160);
  $experience = clean($data['experience'] ?? '', 40);

  if ($name === '' || $phone === '' || $email === '' || $experience === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing']);
    exit;
  }

  $subject = 'Driver application — PAPS Logistics';
  $body = "New driver application from papstexaslogistics.com\n\n"
    . "Name: {$name}\n"
    . "Phone: {$phone}\n"
    . "Email: {$email}\n"
    . "Experience: {$experience}\n";
  $reply = $email;

  $fsPayload['_subject'] = $subject;
  $fsPayload['name'] = $name;
  $fsPayload['phone'] = $phone;
  $fsPayload['email'] = $email;
  $fsPayload['experience'] = $experience;
} else {
  http_response_code(422);
  echo json_encode(['ok' => false, 'error' => 'unknown_form']);
  exit;
}

if ($reply !== '') {
  $fsPayload['_replyto'] = $reply;
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '0';
$bucket = sys_get_temp_dir() . '/paps-form-' . hash('sha256', $ip);
if (is_file($bucket) && (time() - (int) filemtime($bucket)) < 20) {
  http_response_code(429);
  echo json_encode(['ok' => false, 'error' => 'slow']);
  exit;
}

$fs = send_via_formsubmit($to, $fsPayload);
if (is_array($fs) && ($fs['success'] === true || $fs['success'] === 'true')) {
  @touch($bucket);
  echo json_encode(['ok' => true, 'via' => 'formsubmit']);
  exit;
}

$fromDomain = $_SERVER['HTTP_HOST'] ?? 'papstexaslogistics.com';
$fromDomain = preg_replace('/[^a-zA-Z0-9.-]/', '', $fromDomain) ?: 'papstexaslogistics.com';
$fromEmail = 'noreply@' . $fromDomain;

$headers = [
  'MIME-Version: 1.0',
  'Content-Type: text/plain; charset=UTF-8',
  'From: PAPS Website <' . $fromEmail . '>',
  'Reply-To: ' . ($reply !== '' ? $reply : $to),
  'X-Mailer: PAPS-Web',
];

$sent = @mail($to, $subject, $body, implode("\r\n", $headers));

if ($sent) {
  @touch($bucket);
  echo json_encode(['ok' => true, 'via' => 'mail']);
  exit;
}

http_response_code(500);
echo json_encode([
  'ok' => false,
  'error' => 'send_failed',
  'hint' => 'Activate FormSubmit for admin@papstexaslogistics.com or configure Hostinger email.',
]);
