<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

/**
 * Fast, bounded Gmail SMTP transport.
 *
 * The OTP request must never spend a minute waiting on DNS/SMTP retries.
 * Gmail supports authenticated submission on 465 (implicit TLS) and 587
 * (STARTTLS). We try 465 first, then 587, with short connection/response
 * timeouts. cURL is intentionally not used for SMTP here; the application
 * still uses cURL for its Gemini integration.
 */
function smtp_read($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    if ($response === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) throw new RuntimeException('Gmail SMTP response timed out.');
        throw new RuntimeException('Gmail SMTP closed the connection unexpectedly.');
    }
    return $response;
}

function smtp_expect($socket, array $codes): string {
    $response = smtp_read($socket);
    $code = (int)substr($response, 0, 3);
    if (!in_array($code, $codes, true)) {
        throw new RuntimeException('SMTP error '.$code.': '.trim(preg_replace('/\s+/', ' ', $response)));
    }
    return $response;
}

function smtp_write_all($socket, string $data): void {
    $length = strlen($data);
    $offset = 0;
    while ($offset < $length) {
        $written = fwrite($socket, substr($data, $offset));
        if ($written === false || $written === 0) throw new RuntimeException('Unable to write to Gmail SMTP.');
        $offset += $written;
    }
}

function smtp_cmd($socket, string $command, array $codes): string {
    smtp_write_all($socket, $command."\r\n");
    return smtp_expect($socket, $codes);
}

function smtp_auth($socket, string $ehloResponse): void {
    $username = trim(MAIL_USERNAME);
    // Google App Passwords are displayed as groups separated by spaces.
    $password = preg_replace('/\s+/', '', MAIL_PASSWORD) ?? MAIL_PASSWORD;

    $auth = '';
    foreach (preg_split('/\r?\n/', $ehloResponse) as $line) {
        if (preg_match('/^250[ -]AUTH(?:[ =](.*))?$/i', trim($line), $m)) {
            $auth = strtoupper(trim($m[1] ?? ''));
            break;
        }
    }

    // Prefer AUTH PLAIN; Gmail also accepts AUTH LOGIN for App Passwords.
    if ($auth === '' || str_contains($auth, 'PLAIN')) {
        try {
            $payload = base64_encode("\0".$username."\0".$password);
            smtp_cmd($socket, 'AUTH PLAIN '.$payload, [235]);
            return;
        } catch (Throwable $plainError) {
            $firstError = $plainError;
        }
    }

    if ($auth === '' || str_contains($auth, 'LOGIN')) {
        try {
            smtp_cmd($socket, 'AUTH LOGIN', [334]);
            smtp_cmd($socket, base64_encode($username), [334]);
            smtp_cmd($socket, base64_encode($password), [235]);
            return;
        } catch (Throwable $loginError) {
            $firstError = $loginError;
        }
    }

    throw new RuntimeException($firstError->getMessage() ?? 'Gmail did not accept SMTP authentication.');
}

function smtp_connect_and_auth() {
    if (trim(MAIL_USERNAME) === '' || trim(MAIL_PASSWORD) === '') {
        throw new RuntimeException('Gmail SMTP username or App Password is not configured.');
    }

    $context = stream_context_create([
        'socket' => ['tcp_nodelay' => true],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => MAIL_HOST,
            'crypto_method' => STREAM_CRYPTO_METHOD_TLS_CLIENT,
        ],
    ]);

    // Prefer implicit TLS 465, then STARTTLS 587. Both are documented Gmail
    // submission ports; trying both also handles providers that block one port.
    $ports = (int)MAIL_PORT === 587 ? [587, 465] : [465, 587];
    $lastError = null;

    foreach ($ports as $port) {
        $implicitTls = ($port === 465);
        $target = ($implicitTls ? 'ssl://' : 'tcp://').MAIL_HOST.':'.$port;
        $socket = @stream_socket_client(
            $target,
            $errno,
            $errstr,
            MAIL_SMTP_TIMEOUT_SECONDS,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!$socket) {
            $lastError = 'Unable to connect to Gmail SMTP on port '.$port.' ('.$errno.'): '.$errstr;
            continue;
        }

        stream_set_timeout($socket, MAIL_SMTP_TIMEOUT_SECONDS);
        try {
            smtp_expect($socket, [220]);
            $ehlo = smtp_cmd($socket, 'EHLO core4.greatsolomonmpservices.com', [250]);

            if (!$implicitTls) {
                smtp_cmd($socket, 'STARTTLS', [220]);
                $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                if ($crypto !== true) throw new RuntimeException('Gmail STARTTLS negotiation failed on port 587.');
                $ehlo = smtp_cmd($socket, 'EHLO core4.greatsolomonmpservices.com', [250]);
            }

            try {
                smtp_auth($socket, $ehlo);
            } catch (Throwable $authError) {
                $message = $authError->getMessage();
                if (preg_match('/\b535\b|5\.7\.8|5\.7\.80|5\.7\.90|authentication|app password|credentials/i', $message)) {
                    throw new RuntimeException('Gmail rejected the SMTP credentials. Verify 2-Step Verification and use a fresh Google App Password with no spaces.');
                }
                throw $authError;
            }

            return $socket;
        } catch (Throwable $e) {
            $lastError = $e->getMessage();
            @fclose($socket);
            // Credential errors are deterministic; do not waste another
            // connection attempt on the second port.
            if (str_contains(strtolower($lastError), 'smtp credentials') || str_contains(strtolower($lastError), 'app password')) {
                throw new RuntimeException($lastError, 0, $e);
            }
        }
    }

    throw new RuntimeException($lastError ?: 'Unable to connect to Gmail SMTP.');
}

function otp_mail_log(string $message): void {
    $dir = dirname(__DIR__).'/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @error_log('[OTP mail] '.$message);
    @file_put_contents($dir.'/otp-mail.log', date('c').' '.$message."\n", FILE_APPEND | LOCK_EX);
}

function native_mail_send(string $recipient, string $subject, string $htmlBody): bool {
    if (!function_exists('mail')) return false;
    $from = OTP_SENDER_EMAIL ?: MAIL_FROM_EMAIL;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) return false;
    $safeSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';
    $headers = "MIME-Version: 1.0\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "From: ".MAIL_FROM_NAME." <".$from.">\r\n";
    return @mail($recipient, $safeSubject, $htmlBody, $headers);
}

function smtp_send_message(string $recipient, string $subject, string $htmlBody): void {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The recipient email address is invalid.');
    $from = OTP_SENDER_EMAIL ?: MAIL_FROM_EMAIL;
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('The configured sender email address is invalid.');
    if (trim(MAIL_USERNAME) === '' || trim(MAIL_PASSWORD) === '') throw new RuntimeException('Gmail SMTP username or App Password is not configured.');

    $socket = smtp_connect_and_auth();
    try {
        smtp_cmd($socket, 'MAIL FROM:<'.$from.'>', [250]);
        smtp_cmd($socket, 'RCPT TO:<'.$recipient.'>', [250, 251]);
        smtp_cmd($socket, 'DATA', [354]);

        $safeSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';
        $headers = [
            'Date: '.date(DATE_RFC2822),
            'From: '.MAIL_FROM_NAME.' <'.$from.'>',
            'To: <'.$recipient.'>',
            'Subject: '.$safeSubject,
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: Great Solomon CT4 OTP Mailer'
        ];
        $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $body = preg_replace('/(^|\n)\./', '$1..', $body) ?? $body;
        $body = str_replace("\n", "\r\n", $body);
        smtp_write_all($socket, implode("\r\n", $headers)."\r\n\r\n".$body."\r\n.\r\n");
        smtp_expect($socket, [250]);
        try { smtp_cmd($socket, 'QUIT', [221, 250]); } catch (Throwable $ignored) {}
    } finally {
        @fclose($socket);
    }
}

function send_otp_email(string $recipient, string $recipientName, string $otp): void {
    $safeName = htmlspecialchars($recipientName ?: 'Administrator', ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');
    $body = '<!doctype html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f7f3ff;padding:30px">'
          . '<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;padding:30px;border:1px solid #ddd6fe">'
          . '<h2 style="color:#6d28d9;margin-top:0">Great Solomon Manpower Services Inc.</h2>'
          . '<p style="color:#475569">Core Transaction 4 security verification</p>'
          . '<p>Hello '.$safeName.',</p><p>Your one-time verification code is:</p>'
          . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#6d28d9;text-align:center;padding:18px;background:#f3e8ff;border-radius:12px">'.$safeOtp.'</div>'
          . '<p>This code expires in '.OTP_EXPIRY_MINUTES.' minutes. If you did not attempt to sign in, you can ignore this email.</p>'
          . '<p style="color:#6b7280;font-size:13px">Sent by the CT4 security mailbox.</p></div></body></html>';
    $subject = 'Great Solomon Manpower Services Inc. — Security Verification Code';
    $recipientKey = hash('sha256', strtolower($recipient));
    try {
        smtp_send_message($recipient, $subject, $body);
        otp_mail_log('OTP SMTP delivery accepted for '.$recipientKey);
        return;
    } catch (Throwable $smtpError) {
        otp_mail_log('OTP SMTP delivery failed for '.$recipientKey.': '.$smtpError->getMessage());
    }

    // Native mail is optional and disabled by default because some hosts have
    // a broken local MTA that can block the request for a long time.
    if (MAIL_FALLBACK_ENABLED && native_mail_send($recipient, $subject, $body)) {
        otp_mail_log('OTP native-mail fallback accepted for '.$recipientKey);
        return;
    }

    throw new RuntimeException('OTP email delivery failed: '.($smtpError->getMessage() ?? 'SMTP unavailable.'), 0, $smtpError ?? null);
}

function send_feedback_email(string $recipient, string $senderName, string $senderRole, string $feedback): void {
    $name=htmlspecialchars($senderName,ENT_QUOTES,'UTF-8');
    $role=htmlspecialchars($senderRole,ENT_QUOTES,'UTF-8');
    $bodyText=nl2br(htmlspecialchars($feedback,ENT_QUOTES,'UTF-8'));
    $body='<html><body style="font-family:Arial,sans-serif"><h2>New User Feedback</h2>'
         .'<p><strong>Name:</strong> '.$name.'</p><p><strong>Role:</strong> '.$role.'</p>'
         .'<p><strong>Feedback:</strong></p><div style="padding:16px;background:#f8fafc;border-radius:10px">'.$bodyText.'</div>'
         .'<p style="color:#64748b;font-size:12px">Sent from Great Solomon Manpower Services Inc. Core Transaction 4.</p></body></html>';
    smtp_send_message($recipient, 'Great Solomon CT4 — User Feedback', $body);
}
