<?php
// MZL Solution - website contact form handler.
// Sends form submissions by email to the address below.

$TO        = 'mzl@mzlsolution.com';
$FROM      = 'noreply@mzlsolution.com';
$FROM_NAME = 'MZL Solution Website';

$wantsJson = isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false;

function respond($ok, $message, $wantsJson) {
    if ($wantsJson) {
        header('Content-Type: application/json');
        http_response_code($ok ? 200 : 400);
        echo json_encode(['ok' => $ok, 'message' => $message]);
    } else {
        header('Location: ' . ($ok ? '/thanks.html' : '/#contact'));
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    respond(false, 'Invalid request.', $wantsJson);
}

// Spam protection: hidden honeypot field must stay empty,
// and the form must not be submitted within 3 seconds of loading.
if (!empty($_POST['website'])) {
    respond(true, 'Thank you!', $wantsJson);
}
$started = isset($_POST['ts']) ? (int)$_POST['ts'] : 0;
if ($started > 0 && (time() - $started) < 3) {
    respond(true, 'Thank you!', $wantsJson);
}

function field($key, $max = 500) {
    $v = isset($_POST[$key]) ? trim((string)$_POST[$key]) : '';
    $v = str_replace(["\r", "\0"], '', $v);
    return mb_substr($v, 0, $max);
}
function oneLine($v) {
    return trim(preg_replace('/[\r\n]+/', ' ', $v));
}

$name    = oneLine(field('name', 120));
$company = oneLine(field('company', 160));
$phone   = oneLine(field('phone', 40));
$email   = oneLine(field('email', 160));
$type    = oneLine(field('type', 80));
$service = oneLine(field('service', 80));
$city    = oneLine(field('city', 120));
$date    = oneLine(field('date', 20));
$message = field('message', 5000);

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    respond(false, 'Please enter your name and a valid email address.', $wantsJson);
}

$subject = 'New website request: ' . ($type ?: 'Contact') . ' - ' . $name . ($company ? " ($company)" : '');

$body  = "New request from mzlsolution.com\n";
$body .= "----------------------------------------\n";
$body .= "Name:           $name\n";
$body .= "Company:        " . ($company ?: '-') . "\n";
$body .= "Email:          $email\n";
$body .= "Phone:          " . ($phone ?: '-') . "\n";
$body .= "Request type:   " . ($type ?: '-') . "\n";
$body .= "Service:        " . ($service ?: '-') . "\n";
$body .= "Site city:      " . ($city ?: '-') . "\n";
$body .= "Preferred date: " . ($date ?: '-') . "\n";
$body .= "----------------------------------------\n";
$body .= "Scope / details:\n" . ($message ?: '-') . "\n\n";
$body .= "Reply to this email to answer the customer directly.\n";
$body .= "Sent: " . date('Y-m-d H:i:s T') . " | IP: " . ($_SERVER['REMOTE_ADDR'] ?? '-') . "\n";

$headers  = "From: $FROM_NAME <$FROM>\r\n";
$headers .= "Reply-To: $name <$email>\r\n";
$headers .= "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "X-Mailer: PHP/" . phpversion();

$encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
$sent = mail($TO, $encodedSubject, $body, $headers, '-f' . $FROM);

if ($sent) {
    respond(true, 'Thank you! Your request was sent. We will get back to you shortly.', $wantsJson);
}
respond(false, 'Sorry, something went wrong. Please email us at mzl@mzlsolution.com or call (645) 999-1556.', $wantsJson);
