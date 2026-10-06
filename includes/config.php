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

// --- Authentication: WebAuthn / Face ID ------------------------------------
// Biometric templates are never stored by this application; the platform
// authenticator keeps them on the user's device.

define('WEBAUTHN_TIMEOUT_MS', max(30000, min(180000, (int)(getenv('GSMS_WEBAUTHN_TIMEOUT_MS') ?: 120000))));

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
