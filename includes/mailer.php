<?php
declare(strict_types=1);
require_once __DIR__.'/config.php';

/**
 * Lightweight Gmail SMTP sender.
 *
 * Supports Gmail App Password authentication over STARTTLS (587) and implicit
 * TLS (465). No third-party mail package is required, which keeps the existing
 * deployment structure and UI unchanged.
 */
function smtp_read($socket): string {
    $response = '';
    while (($line = fgets($socket, 515)) !== false) {
        $response .= $line;
        if (strlen($line) >= 4 && $line[3] === ' ') break;
    }
    if ($response === '') {
        $meta = stream_get_meta_data($socket);
        if (!empty($meta['timed_out'])) throw new RuntimeException('SMTP server response timed out.');
        throw new RuntimeException('SMTP server closed the connection unexpectedly.');
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
        if ($written === false || $written === 0) throw new RuntimeException('Unable to write to the SMTP server.');
        $offset += $written;
    }
}

function smtp_cmd($socket, string $command, array $codes): string {
    smtp_write_all($socket, $command."\r\n");
    return smtp_expect($socket, $codes);
}

/**
 * Resolve smtp.gmail.com through normal DNS first, then DNS-over-HTTPS.
 * This is important on container hosts whose DNS resolver is intermittently
 * unavailable even though outbound HTTPS is available.
 */
function smtp_resolve_host(string $host): array {
    $addresses = [];
    $native = @gethostbynamel($host);
    if (is_array($native)) $addresses = array_values(array_unique(array_filter($native, static fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false)));
    if ($addresses) return $addresses;

    if (!function_exists('curl_init')) return [];
    $providers = [
        ['https://cloudflare-dns.com/dns-query?name='.rawurlencode($host).'&type=A', 'cloudflare-dns.com:443:1.1.1.1'],
        ['https://dns.google/resolve?name='.rawurlencode($host).'&type=A', 'dns.google:443:8.8.8.8'],
    ];
    foreach ($providers as [$url, $resolve]) {
        $ch = @curl_init($url);
        if ($ch === false) continue;
        @curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => ['Accept: application/dns-json'],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_RESOLVE => [$resolve],
            CURLOPT_USERAGENT => 'Great Solomon CT4 DNS resolver',
        ]);
        $body = @curl_exec($ch);
        $status = (int)@curl_getinfo($ch, CURLINFO_HTTP_CODE);
        @curl_close($ch);
        if ($body === false || $status < 200 || $status >= 300) continue;
        $json = json_decode((string)$body, true);
        foreach (($json['Answer'] ?? []) as $answer) {
            if (($answer['type'] ?? null) === 1 && filter_var($answer['data'] ?? '', FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $addresses[] = $answer['data'];
            }
        }
        if ($addresses) return array_values(array_unique($addresses));
    }
    return [];
}

function smtp_ehlo_capabilities(string $response): array {
    $caps = [];
    foreach (preg_split('/\r?\n/', $response) as $line) {
        $line = trim($line);
        if (preg_match('/^250[ -]([A-Za-z0-9][A-Za-z0-9-]*)(?:[ =](.*))?$/', $line, $m)) {
            $caps[strtoupper($m[1])] = strtoupper(trim($m[2] ?? ''));
        }
    }
    return $caps;
}

function smtp_auth($socket, string $ehloResponse): void {
    $username = trim(MAIL_USERNAME);
    $password = preg_replace('/\s+/', '', MAIL_PASSWORD) ?? MAIL_PASSWORD;
    $caps = smtp_ehlo_capabilities($ehloResponse);
    $auth = $caps['AUTH'] ?? '';

    // PLAIN is the cleanest Gmail App Password exchange. LOGIN is retained as
    // a fallback for servers/proxies that advertise LOGIN instead.
    if ($auth === '' || str_contains($auth, 'PLAIN')) {
        try {
            $payload = base64_encode("\0".$username."\0".$password);
            smtp_cmd($socket, 'AUTH PLAIN '.$payload, [235]);
            return;
        } catch (Throwable $e) {
            $plainError = $e;
        }
    }
    if ($auth === '' || str_contains($auth, 'LOGIN')) {
        try {
            smtp_cmd($socket, 'AUTH LOGIN', [334]);
            smtp_cmd($socket, base64_encode($username), [334]);
            smtp_cmd($socket, base64_encode($password), [235]);
            return;
        } catch (Throwable $e) {
            $loginError = $e;
        }
    }
    $detail = isset($loginError) ? $loginError->getMessage() : (isset($plainError) ? $plainError->getMessage() : 'Gmail did not advertise a supported AUTH method.');
    throw new RuntimeException($detail);
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

    $configuredPort = (int)MAIL_PORT;
    $ports = $configuredPort === 587 ? [587, 465] : [465, 587];
    $addresses = smtp_resolve_host(MAIL_HOST);
    $lastError = null;
    if (!$addresses) throw new RuntimeException('Unable to resolve '.MAIL_HOST.' from the application container.');

    foreach ($ports as $port) {
        $implicitTls = ($port === 465);
        foreach ($addresses as $address) {
            $transport = $implicitTls ? 'ssl://' : 'tcp://';
            $target = $transport.$address.':'.$port;
            $socket = @stream_socket_client($target, $errno, $errstr, MAIL_SMTP_TIMEOUT_SECONDS, STREAM_CLIENT_CONNECT, $context);
            if (!$socket) {
                $lastError = 'Unable to connect to Gmail SMTP '.$address.':'.$port.' ('.$errno.'): '.$errstr;
                continue;
            }
            stream_set_timeout($socket, MAIL_SMTP_TIMEOUT_SECONDS);
            try {
                smtp_expect($socket, [220]);
                $ehlo = smtp_cmd($socket, 'EHLO core4.greatsolomonmpservices.com', [250]);
                if (!$implicitTls) {
                    smtp_cmd($socket, 'STARTTLS', [220]);
                    $crypto = @stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
                    if ($crypto !== true) throw new RuntimeException('TLS negotiation failed with Gmail on port 587.');
                    $ehlo = smtp_cmd($socket, 'EHLO core4.greatsolomonmpservices.com', [250]);
                }
                smtp_auth($socket, $ehlo);
                return $socket;
            } catch (Throwable $e) {
                $lastError = $e->getMessage();
                @fclose($socket);
                $lower = strtolower($lastError);
                if (str_contains($lower, 'smtp error 535') || str_contains($lower, '5.7.8') || str_contains($lower, 'authentication failed') || str_contains($lower, 'invalid credentials')) {
                    throw new RuntimeException('Gmail authentication failed. Verify 2-Step Verification and generate a fresh 16-character Google App Password.');
                }
            }
        }
    }
    throw new RuntimeException($lastError ?: 'Unable to connect to Gmail SMTP.');
}

function otp_mail_log(string $message): void {
    $dir = dirname(__DIR__).'/storage/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    @error_log('[OTP mail] '.$message);
    $file = $dir.'/otp-mail.log';
    @file_put_contents($file, date('c').' '.$message."\n", FILE_APPEND | LOCK_EX);
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

function smtp_message_payload(string $recipient, string $subject, string $htmlBody): array {
    $from = OTP_SENDER_EMAIL ?: MAIL_FROM_EMAIL;
    $safeSubject = '=?UTF-8?B?'.base64_encode($subject).'?=';
    $headers = [
        'Date: '.date(DATE_RFC2822),
        'From: '.MAIL_FROM_NAME.' <'.$from.'>',
        'To: <'.$recipient.'>',
        'Subject: '.$safeSubject,
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: 8bit',
        'X-Mailer: Great Solomon Manpower Services Inc. Core Transaction 4'
    ];
    $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
    $body = preg_replace('/(^|\n)\./', '$1..', $body) ?? $body;
    $body = str_replace("\n", "\r\n", $body);
    $message = implode("\r\n", $headers)."\r\n\r\n".$body;
    return [$from, $message];
}

/**
 * SMTP through libcurl. The Docker image installs PHP cURL and libcurl with
 * TLS support. This avoids several edge cases of manually implementing SMTP
 * authentication/STARTTLS while retaining the socket transport below as a
 * fallback for hosts where cURL SMTP is unavailable.
 */
function smtp_send_via_curl(string $recipient, string $subject, string $htmlBody): void {
    if (!function_exists('curl_init')) {
        throw new RuntimeException('PHP cURL SMTP transport is unavailable.');
    }
    $protocols = function_exists('curl_version') ? (curl_version()['protocols'] ?? []) : [];
    if (!in_array('smtp', $protocols, true) && !in_array('smtps', $protocols, true)) {
        throw new RuntimeException('This PHP cURL build does not support SMTP.');
    }

    [$from, $message] = smtp_message_payload($recipient, $subject, $htmlBody);
    $port = (int)MAIL_PORT;
    if (!in_array($port, [465, 587], true)) {
        throw new RuntimeException('Gmail SMTP requires port 587 (STARTTLS) or 465 (TLS).');
    }

    $scheme = $port === 465 ? 'smtps' : 'smtp';
    $url = $scheme.'://'.MAIL_HOST.':'.$port;
    $stream = fopen('php://temp', 'r+');
    if ($stream === false) throw new RuntimeException('Unable to prepare SMTP message stream.');
    // cURL terminates the SMTP DATA block itself; do not append the SMTP dot terminator.
    fwrite($stream, $message."\r\n");
    rewind($stream);

    $ch = curl_init($url);
    if ($ch === false) {
        fclose($stream);
        throw new RuntimeException('Unable to initialize the SMTP transport.');
    }

    curl_setopt_array($ch, [
        CURLOPT_USERNAME        => MAIL_USERNAME,
        CURLOPT_PASSWORD        => preg_replace('/\s+/', '', MAIL_PASSWORD) ?? MAIL_PASSWORD,
        CURLOPT_MAIL_FROM       => $from,
        CURLOPT_MAIL_RCPT       => [$recipient],
        CURLOPT_UPLOAD          => true,
        CURLOPT_READDATA        => $stream,
        CURLOPT_INFILESIZE      => strlen($message) + 2,
        CURLOPT_CONNECTTIMEOUT  => MAIL_SMTP_TIMEOUT_SECONDS,
        CURLOPT_TIMEOUT         => MAIL_SMTP_TIMEOUT_SECONDS + 10,
        CURLOPT_RETURNTRANSFER  => true,
        CURLOPT_SSL_VERIFYPEER  => true,
        CURLOPT_SSL_VERIFYHOST  => 2,
        CURLOPT_USERAGENT       => 'Great Solomon CT4 OTP Mailer',
    ]);
    if ($port === 587) {
        curl_setopt($ch, CURLOPT_USE_SSL, CURLUSESSL_ALL);
    }

    $result = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $info = curl_getinfo($ch);
    curl_close($ch);
    fclose($stream);

    if ($result === false || $errno !== 0) {
        $detail = $error !== '' ? $error : ('cURL SMTP error '.$errno);
        throw new RuntimeException($detail);
    }
    $responseCode = (int)($info['http_code'] ?? 0);
    // SMTP does not use HTTP status codes; successful curl SMTP transfers
    // normally expose a zero HTTP code. A non-zero HTTP code here is a proxy
    // response and should be treated as an error.
    if ($responseCode >= 400) {
        throw new RuntimeException('SMTP transport proxy returned HTTP '.$responseCode.'.');
    }
}

function smtp_send_message(string $recipient, string $subject, string $htmlBody): void {
    if (!filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The recipient email address is invalid.');
    }
    if (!filter_var(OTP_SENDER_EMAIL ?: MAIL_FROM_EMAIL, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('The configured sender email address is invalid.');
    }
    if (trim(MAIL_USERNAME) === '' || trim(MAIL_PASSWORD) === '') {
        throw new RuntimeException('Gmail SMTP username or App Password is not configured. Set GSMS_MAIL_USERNAME and GSMS_MAIL_PASSWORD (or GSMS_MAIL_PASSWORD_FILE for a Docker secret).');
    }

    $transport = MAIL_SMTP_TRANSPORT;
    if ($transport === 'curl') {
        smtp_send_via_curl($recipient, $subject, $htmlBody);
        return;
    }

    $socket = smtp_connect_and_auth();
    try {
        $from = OTP_SENDER_EMAIL ?: MAIL_FROM_EMAIL;
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
            'X-Mailer: Great Solomon Manpower Services Inc. Core Transaction 4'
        ];

        // Normalize line endings and SMTP-dot-stuff every body line beginning
        // with a period. The final DATA terminator is sent separately.
        $body = str_replace(["\r\n", "\r"], "\n", $htmlBody);
        $body = preg_replace('/(^|\n)\./', '$1..', $body) ?? $body;
        $body = str_replace("\n", "\r\n", $body);
        $message = implode("\r\n", $headers)."\r\n\r\n".$body."\r\n.";
        // Once Gmail returns 250 for DATA, the message has been accepted.
        // A delayed/failed QUIT response must not turn a successful delivery
        // into a false failure (which could trigger a duplicate fallback mail).
        smtp_write_all($socket, $message."\r\n");
        smtp_expect($socket, [250]);
        try { smtp_cmd($socket, 'QUIT', [221, 250]); } catch (Throwable $ignored) {}
    } finally {
        fclose($socket);
    }
}

function send_otp_email(string $recipient, string $recipientName, string $otp): void {
    $safeName = htmlspecialchars($recipientName ?: 'Administrator', ENT_QUOTES, 'UTF-8');
    $safeOtp = htmlspecialchars($otp, ENT_QUOTES, 'UTF-8');

    $body = '<!doctype html><html><body style="margin:0;font-family:Arial,sans-serif;background:#f7f3ff;padding:30px">'
          . '<div style="max-width:560px;margin:auto;background:#fff;border-radius:16px;padding:30px;border:1px solid #ddd6fe">'
          . '<h2 style="color:#6d28d9;margin-top:0">Great Solomon Manpower Services Inc.</h2>'
          . '<p style="color:#475569">Core Transaction 4 security verification</p>'
          . '<p>Hello '.$safeName.',</p>'
          . '<p>Your one-time verification code is:</p>'
          . '<div style="font-size:34px;font-weight:800;letter-spacing:8px;color:#6d28d9;text-align:center;padding:18px;background:#f3e8ff;border-radius:12px">'.$safeOtp.'</div>'
          . '<p>This code expires in '.OTP_EXPIRY_MINUTES.' minutes. If you did not attempt to sign in, you can ignore this email.</p>'
          . '<p style="color:#6b7280;font-size:13px">Sent by the CT4 security mailbox.</p>'
          . '</div></body></html>';

    $subject = 'Great Solomon Manpower Services Inc. — Security Verification Code';
    $recipientKey=hash('sha256', strtolower($recipient));
    $lastError=null;

    // A single SMTP connection can fail because of a transient DNS/TLS/network
    // problem even when Gmail credentials are correct. Retry the exact same
    // OTP so that a successful retry never creates a different code.
    for($attempt=1;$attempt<=OTP_MAIL_RETRIES;$attempt++){
        try {
            smtp_send_message($recipient, $subject, $body);
            otp_mail_log('OTP SMTP delivery accepted for '.$recipientKey.' on attempt '.$attempt);
            return;
        } catch(Throwable $smtpError) {
            $lastError=$smtpError;
            otp_mail_log('OTP SMTP attempt '.$attempt.' failed for '.$recipientKey.': '.$smtpError->getMessage());
            $message=strtolower($smtpError->getMessage());
            // Authentication/configuration errors will not be fixed by retrying.
            if(str_contains($message,'smtp error 535') || str_contains($message,'authentication') || str_contains($message,'app password')) break;
            if($attempt<OTP_MAIL_RETRIES){
                usleep(OTP_MAIL_RETRY_DELAY_MS*1000*$attempt);
            }
        }
    }

    // Keep the existing native-mail fallback as a last resort. A true return
    // value means the configured MTA accepted the message; it is not treated
    // as proof of inbox delivery, but it prevents unnecessary OTP failures on
    // hosts where outbound SMTP is blocked.
    if(MAIL_FALLBACK_ENABLED && native_mail_send($recipient, $subject, $body)){
        otp_mail_log('OTP native-mail fallback accepted for '.$recipientKey);
        return;
    }

    $detail=$lastError ? $lastError->getMessage() : 'No mail transport accepted the message.';
    throw new RuntimeException('OTP email delivery failed after '.OTP_MAIL_RETRIES.' SMTP attempt(s): '.$detail, 0, $lastError);
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
