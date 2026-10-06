<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

$user = current_user();
if (!$user || strtolower((string)($user['role'] ?? '')) !== 'administrator') {
    http_response_code(403);
    exit('Forbidden');
}

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

$result = [
    'smtp_host' => MAIL_HOST,
    'smtp_port' => MAIL_PORT,
    'smtp_username_configured' => MAIL_USERNAME !== '',
    'smtp_password_configured' => MAIL_PASSWORD !== '',
    'sender_configured' => filter_var(OTP_SENDER_EMAIL, FILTER_VALIDATE_EMAIL) !== false,
    'transport' => MAIL_SMTP_TRANSPORT === 'auto' ? 'cURL SMTP then socket fallback' : MAIL_SMTP_TRANSPORT,
    'smtp_timeout_seconds' => MAIL_SMTP_TIMEOUT_SECONDS,
    'smtp_dns_resolves' => (bool)gethostbyname(MAIL_HOST) && gethostbyname(MAIL_HOST) !== MAIL_HOST,
    'status' => 'configuration_error',
];

if ($result['smtp_username_configured'] && $result['smtp_password_configured'] && $result['sender_configured']) {
    $result['status'] = 'configuration_present';
    try {
        $socket = smtp_connect_and_auth();
        fclose($socket);
        $result['status'] = 'smtp_authentication_ok';
        $result['message'] = 'SMTP connection and authentication succeeded. OTP delivery can proceed.';
    } catch (Throwable $e) {
        $result['status'] = 'smtp_failed';
        $result['message'] = $e->getMessage();
        $result['hint'] = 'For Gmail, use smtp.gmail.com with port 587/STARTTLS or 465/TLS and a 16-character Google App Password. Do not use the normal Gmail password.';
    }
} else {
    $result['message'] = 'SMTP credentials or sender address are missing from the deployment environment.';
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
