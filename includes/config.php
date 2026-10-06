<?php
// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// config.php — Centralised runtime configuration.
// All secrets come from environment variables; no hard-coded
// credentials exist in this file (HostForge resilience rule).
// =============================================================

// --- Load .env file (local/dev only; production uses real env vars) ---
function gsms_load_env_file(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $file = dirname(__DIR__).'/.env';
    if (!is_file($file) || !is_readable($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) return;
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$name, $value] = array_map('trim', explode('=', $line, 2));
        $value = trim($value, " \"'");
        if ($name !== '' && getenv($name) === false) {
            putenv($name.'='.$value);
        }
    }
}
gsms_load_env_file();

// --- Ephemeral directory bootstrap (HostForge ephemeral containers) ---
// Auto-create write-dependent directories so the app survives cold starts.
(function (): void {
    $base = dirname(__DIR__);
    foreach (['storage/logs', 'storage/exports', 'storage/reports'] as $dir) {
        $path = $base.'/'.$dir;
        if (!is_dir($path)) {
            @mkdir($path, 0755, true);
        }
    }
})();

// --- Database configuration -------------------------------------------------
// Supports:
//   GSMS_DB_* (preferred)
//   DATABASE_URL / MYSQL_URL
//   DB_* / MYSQL_* variables commonly injected by managed MySQL services.
// Values are resolved once, before the constants below are defined.
(function (): void {
    $read = static function (string ...$names): ?string {
        foreach ($names as $name) {
            $value = getenv($name);
            if ($value !== false && trim((string)$value) !== '') {
                return trim((string)$value);
            }
        }
        return null;
    };

    // A URL is useful on platforms that provide one DATABASE_URL secret.
    $url = $read('DATABASE_URL', 'MYSQL_URL');
    if ($url !== null && !$read('GSMS_DB_HOST')) {
        $parsed = parse_url($url);
        if ($parsed !== false && !empty($parsed['host'])) {
            putenv('GSMS_DB_HOST='.urldecode((string)$parsed['host']));
            if (isset($parsed['port'])) putenv('GSMS_DB_PORT='.(int)$parsed['port']);
            if (isset($parsed['user'])) putenv('GSMS_DB_USER='.urldecode((string)$parsed['user']));
            if (isset($parsed['pass'])) putenv('GSMS_DB_PASS='.urldecode((string)$parsed['pass']));
            if (!empty($parsed['path'])) {
                putenv('GSMS_DB_NAME='.ltrim(urldecode((string)$parsed['path']), '/'));
            }
        }
    }

    // Common provider/container naming conventions. Project-specific values
    // always take precedence over these fallbacks.
    $map = [
        'GSMS_DB_HOST' => ['DB_HOST', 'MYSQL_HOST'],
        'GSMS_DB_PORT' => ['DB_PORT', 'MYSQL_PORT', 'MYSQL_TCP_PORT'],
        'GSMS_DB_NAME' => ['DB_DATABASE', 'MYSQL_DATABASE'],
        'GSMS_DB_USER' => ['DB_USERNAME', 'MYSQL_USER'],
        'GSMS_DB_PASS' => ['DB_PASSWORD', 'MYSQL_PASSWORD'],
    ];
    foreach ($map as $target => $sources) {
        if ($read($target) !== null) continue;
        foreach ($sources as $source) {
            $value = getenv($source);
            if ($value !== false && trim((string)$value) !== '') {
                putenv($target.'='.trim((string)$value));
                break;
            }
        }
    }
})();

// --- Application Environment & Debugging ---
define('APP_ENV',   getenv('APP_ENV') ?: (getenv('ENVIRONMENT') ?: 'production'));
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: (APP_ENV !== 'production' ? 'true' : 'false'), FILTER_VALIDATE_BOOLEAN));

if (APP_ENV === 'production' && !APP_DEBUG) {
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
}

// --- Resolved DB constants ---
define('DB_HOST', getenv('GSMS_DB_HOST') ?: '127.0.0.1');
define('DB_PORT', max(1, (int)(getenv('GSMS_DB_PORT') ?: 3306)));
define('DB_NAME', getenv('GSMS_DB_NAME') ?: 'great_solomon_ct4');
define('DB_USER', getenv('GSMS_DB_USER') ?: 'root');
define('DB_PASS', getenv('GSMS_DB_PASS') ?: '');
define('DB_CONNECT_TIMEOUT', max(1, (int)(getenv('GSMS_DB_CONNECT_TIMEOUT') ?: 8)));

// --- Gmail SMTP ---
define('MAIL_HOST', getenv('GSMS_MAIL_HOST') ?: 'smtp.gmail.com');
// Gmail supports STARTTLS on 587 and implicit TLS on 465. 587 remains the
// default for compatibility, while mailer.php can fall back to 465 if a host
// blocks STARTTLS or the configured port is unavailable.
define('MAIL_PORT', max(1, (int)(getenv('GSMS_MAIL_PORT') ?: (getenv('MAIL_PORT') ?: 587))));
define('MAIL_USERNAME', trim((string)(getenv('GSMS_MAIL_USERNAME') ?: (getenv('MAIL_USERNAME') ?: (getenv('GMAIL_SMTP_USERNAME') ?: (getenv('SMTP_USERNAME') ?: 'governancesafety21@gmail.com'))))));
// SMTP password may be supplied as an environment variable or Docker secret.
// Never hard-code the Gmail/App password into the application source.
$mailPassword = getenv('GSMS_MAIL_PASSWORD') ?: (getenv('MAIL_PASSWORD') ?: (getenv('GMAIL_APP_PASSWORD') ?: (getenv('SMTP_PASSWORD') ?: (getenv('SMTP_PASS') ?: ''))));
// Local development may provide GSMS_MAIL_PASSWORD through the project .env file.
// Production should provide it through a server environment variable or Docker secret.
// Never hard-code the Gmail App Password in PHP source.
if (trim((string)$mailPassword) === '') {
    $passwordFile = getenv('GSMS_MAIL_PASSWORD_FILE') ?: (getenv('SMTP_PASSWORD_FILE') ?: '/run/secrets/gsms_mail_password');
    if ($passwordFile && is_readable($passwordFile)) {
        $mailPassword = trim((string)file_get_contents($passwordFile));
    }
}
// Google displays App Passwords in grouped blocks; SMTP authentication requires
// the actual 16-character value without spaces or other whitespace.
$mailPassword = preg_replace('/\\s+/', '', (string)$mailPassword) ?? (string)$mailPassword;
define('MAIL_PASSWORD', trim($mailPassword));
// When the sender is not explicitly configured, use the authenticated Gmail
// account. A fixed sender address can cause Gmail 553/550 errors when a
// deployment changes only GSMS_MAIL_USERNAME.
define('MAIL_FROM_EMAIL', trim((string)(getenv('GSMS_MAIL_FROM_EMAIL') ?: (getenv('MAIL_FROM_EMAIL') ?: MAIL_USERNAME))));
define('MAIL_FROM_NAME', getenv('GSMS_MAIL_FROM_NAME') ?: (getenv('MAIL_FROM_NAME') ?: 'Great Solomon Manpower Services Inc. Core Transaction 4'));
define('OTP_SENDER_EMAIL', trim((string)(getenv('GSMS_OTP_SENDER_EMAIL') ?: (getenv('OTP_SENDER_EMAIL') ?: MAIL_FROM_EMAIL))));
define('MAIL_FALLBACK_ENABLED', filter_var(getenv('GSMS_MAIL_FALLBACK') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('OTP_EXPIRY_MINUTES', 10);
define('OTP_MAX_ATTEMPTS',    5);
// Short server-side resend cooldown prevents accidental duplicate sends while
// keeping the login flow responsive. The browser also mirrors this value.
define('OTP_RESEND_COOLDOWN_SECONDS', 15);
// Keep the pending-login session alive long enough to allow an expired OTP to be resent.
// This does not extend the lifetime of the OTP itself; each generated code still expires separately.
define('OTP_PENDING_SESSION_MINUTES', 30);
// Keep SMTP failures from making login appear frozen; successful Gmail delivery
// is unaffected by this connection/response timeout.
define('MAIL_SMTP_TIMEOUT_SECONDS', max(8, (int)(getenv('GSMS_MAIL_TIMEOUT') ?: 20)));
define('MAIL_SMTP_TRANSPORT', strtolower(trim((string)(getenv('GSMS_MAIL_TRANSPORT') ?: 'socket'))));
// Retry transient SMTP/network failures before falling back to the server mailer.
define('OTP_MAIL_RETRIES', max(1, min(5, (int)(getenv('GSMS_OTP_MAIL_RETRIES') ?: 3))));
define('OTP_MAIL_RETRY_DELAY_MS', max(100, min(2000, (int)(getenv('GSMS_OTP_MAIL_RETRY_DELAY_MS') ?: 350))));

// --- Gemini AI ---
// Never expose this to browser JavaScript.
define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: getenv('GOOGLE_API_KEY') ?: '');
define('GEMINI_MODEL',   getenv('GEMINI_MODEL')   ?: 'gemini-3.8-flash');

// --- HTTPS / Secure-cookie detection (HostForge edge proxy terminates TLS) ---
define('APP_HTTPS', (
    (!empty($_SERVER['HTTPS'])                    && $_SERVER['HTTPS']                    !== 'off') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])   && $_SERVER['HTTP_X_FORWARDED_PROTO']   === 'https') ||
    (!empty($_SERVER['HTTP_X_FORWARDED_SSL'])     && $_SERVER['HTTP_X_FORWARDED_SSL']     === 'on') ||
    ((int)($_SERVER['SERVER_PORT'] ?? 0) === 443)
));

// Apply secure session cookie settings on first include.
// Session security defaults. Keep these consistent across every entry point.
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_samesite', 'Lax');

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => APP_HTTPS,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
