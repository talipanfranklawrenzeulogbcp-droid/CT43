<?php
// Authentication/session bootstrap must load the central configuration BEFORE
// starting the session. This keeps cookie path/secure/SameSite settings identical
// on login, dashboard, nested module pages, index.php, and logout.php.
require_once __DIR__.'/config.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}


function csrf_token(): string {
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="'.htmlspecialchars(csrf_token(), ENT_QUOTES, 'UTF-8').'">';
}

function verify_csrf(?string $token = null): void {
    $token = $token ?? (string)($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
    $stored = (string)($_SESSION['csrf_token'] ?? '');
    if ($stored === '' || $token === '' || !hash_equals($stored, $token)) {
        http_response_code(419);
        throw new RuntimeException('Your security token expired. Refresh the page and try again.');
    }
}

function current_user(): ?array {
    return isset($_SESSION['user']) && is_array($_SESSION['user'])
        ? $_SESSION['user']
        : null;
}

function app_base_path(): string {
    // HostForge/reverse proxies may mount the application below a path prefix.
    // Prefer an explicitly configured prefix, then the forwarded prefix.
    $configured = trim((string)(getenv('APP_BASE_PATH') ?: getenv('GSMS_APP_BASE_PATH')));
    if ($configured !== '') {
        return '/'.trim(str_replace('\\','/',$configured), '/');
    }

    $forwarded = trim((string)($_SERVER['HTTP_X_FORWARDED_PREFIX'] ?? ''));
    if ($forwarded !== '') {
        return '/'.trim(str_replace('\\','/',$forwarded), '/');
    }

    $script = str_replace('\\','/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script === '') return '';

    // Remove the known application endpoint/folder from SCRIPT_NAME.
    $base = preg_replace(
        '#/(?:modules/[^/]+|auth|includes|services)(?:/.*)?$#',
        '',
        $script
    );
    if ($base === null) $base = '';

    // For root-level scripts such as /index.php or /dashboard.php, dirname
    // gives the application mount point (/ or the configured subdirectory).
    if ($base === $script || $base === null) {
        $base = str_replace('\\','/', dirname($script));
    }

    if ($base === '/' || $base === '.' || $base === '') return '';
    return '/'.trim($base, '/');
}

function app_url(string $path = ''): string {
    $base = rtrim(app_base_path(), '/');
    return $base.'/'.ltrim($path, '/');
}

function require_login(): void {
    if (!current_user()) {
        header('Location: '.app_url('/auth/login.php'));
        exit;
    }

    // Enforce the same 5-minute inactivity limit server-side.
    $limit = 5 * 60;
    $last = (int)($_SESSION['last_activity'] ?? time());
    if ((time() - $last) >= $limit) {
        logout_user();
        header('Location: '.app_url('/auth/login.php?reason=inactivity'));
        exit;
    }
    $_SESSION['last_activity'] = time();
}

function require_admin(): void {
    require_login();
    $u = current_user();
    if (($u['role'] ?? '') !== 'Administrator') {
        http_response_code(403);
        echo '<!doctype html><html><head><meta charset="utf-8"><title>Access Denied</title></head><body style="font-family:Arial,sans-serif;padding:40px"><h1>Access Denied</h1><p>This area is available to administrators only.</p><p><a href="'.htmlspecialchars(app_url('/dashboard.php'),ENT_QUOTES,'UTF-8').'">Return to dashboard</a></p></body></html>';
        exit;
    }
}

function login_user(array $user): void {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // Prevent session fixation and make the authenticated session explicit.
    session_regenerate_id(true);
    $_SESSION['last_activity'] = time();
    csrf_token();
    $_SESSION['user'] = [
        'id'    => (int)$user['id'],
        'name'  => (string)$user['name'],
        'email' => (string)$user['email'],
        'role'  => (string)$user['role']
    ];

    // A successful login must never retain unfinished biometric state.
    unset($_SESSION['pending_biometric_user'], $_SESSION['pending_biometric_created'], $_SESSION['pending_registration_user'], $_SESSION['pending_registration_created'], $_SESSION['biometric_enroll_existing']);
}

function logout_user(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) return;

    // Remove server-side authentication state first.
    $_SESSION = [];

    // Expire the cookie using the SAME attributes used when the session was
    // created. This is important behind HTTPS/reverse proxies.
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires'  => time() - 42000,
            'path'     => $p['path'] ?: '/',
            'domain'   => $p['domain'] ?? '',
            'secure'   => (bool)$p['secure'],
            'httponly' => (bool)$p['httponly'],
            'samesite' => $p['samesite'] ?? 'Lax',
        ]);
    }

    session_destroy();
}
