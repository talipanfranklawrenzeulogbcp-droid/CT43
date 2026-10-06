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

$dns = gethostbyname(MAIL_HOST);
$result = [
    'smtp_host' => MAIL_HOST,
    'configured_port' => MAIL_PORT,
    'smtp_ports_tested' => [465, 587],
    'smtp_username_configured' => MAIL_USERNAME !== '',
    'smtp_password_configured' => MAIL_PASSWORD !== '',
    'sender_configured' => filter_var(OTP_SENDER_EMAIL, FILTER_VALIDATE_EMAIL) !== false,
    'transport' => MAIL_SMTP_TRANSPORT,
    'smtp_timeout_seconds' => MAIL_SMTP_TIMEOUT_SECONDS,
    'smtp_dns_resolves' => $dns !== MAIL_HOST,
    'smtp_resolved_address' => $dns !== MAIL_HOST ? $dns : null,
    'smtp_password_length' => strlen(MAIL_PASSWORD),
    'smtp_password_has_whitespace' => (bool)preg_match('/\\s/', MAIL_PASSWORD),
    'status' => 'configuration_error',
];

if (!$result['smtp_username_configured'] || !$result['smtp_password_configured'] || !$result['sender_configured']) {
    $result['message'] = 'SMTP configuration is incomplete. The username, App Password, and sender address must be present.';
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

try {
    $socket = smtp_connect_and_auth();
    fclose($socket);
    $result['status'] = 'smtp_authentication_ok';
    $result['message'] = 'Gmail SMTP connection and authentication succeeded. OTP delivery can proceed.';
} catch (Throwable $e) {
    $result['status'] = 'smtp_failed';
    $result['message'] = $e->getMessage();
    $result['hint'] = 'If DNS is false, fix container DNS/networking. If connection times out on both 465 and 587, the hosting platform is blocking outbound SMTP. If Gmail returns 535/5.7.8, regenerate the Google App Password and verify 2-Step Verification is enabled.';
}

echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
