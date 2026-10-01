<?php
/** Central runtime diagnostics for CT4. Never display exception details to visitors. */
if (defined('CT4_ERROR_HANDLER_LOADED')) { return; }
define('CT4_ERROR_HANDLER_LOADED', true);

$ct4LogDir = dirname(__DIR__) . '/storage/logs';
if (!is_dir($ct4LogDir)) { @mkdir($ct4LogDir, 0750, true); }
$ct4LogFile = $ct4LogDir . '/php-error.log';
ini_set('log_errors', '1');
ini_set('error_log', $ct4LogFile);
ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

if (!function_exists('ct4_log_exception')) {
    function ct4_log_exception(Throwable $e, string $kind = 'uncaught'): string {
        $id = bin2hex(random_bytes(8));
        $entry = sprintf("[%s] [%s] [%s] %s in %s:%d\nRequest: %s %s\n", date('c'), $id, $kind,
            $e->getMessage(), $e->getFile(), $e->getLine(), $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            $_SERVER['REQUEST_URI'] ?? '');
        error_log($entry . $e->getTraceAsString() . "\n");
        return $id;
    }
}

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) return false;
    error_log(sprintf('[%s] [PHP-%d] %s in %s:%d | %s %s', date('c'), $severity, $message, $file, $line,
        $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['REQUEST_URI'] ?? ''));
    return false; // Preserve PHP's normal handling while recording the event.
});

set_exception_handler(static function (Throwable $e): void {
    $id = ct4_log_exception($e, 'uncaught-exception');
    if (headers_sent()) {
        return;
    }

    $message = $e->getMessage();
    $isSetupFailure = $e instanceof PDOException
        || str_contains($message, 'PDO MySQL')
        || str_contains($message, 'Database connection failed');
    $status = $isSetupFailure ? 503 : 500;
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
    $isJson = str_contains($accept, 'application/json')
        || str_starts_with((string)($_SERVER['REQUEST_URI'] ?? ''), '/services/api/');

    http_response_code($status);
    header('Cache-Control: no-store');
    if ($isJson) {
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode([
            'ok' => false,
            'error' => $isSetupFailure
                ? $message
                : 'The server could not complete the request.',
            'reference' => $id,
        ], JSON_UNESCAPED_SLASHES);
        return;
    }

    header('Content-Type: text/html; charset=UTF-8');
    if (is_file(dirname(__DIR__) . '/500.php')) {
        if (!defined('CT4_ERROR_ID')) define('CT4_ERROR_ID', $id);
        require dirname(__DIR__) . '/500.php';
    } else {
        echo 'Internal Server Error. Reference: ' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8');
    }
});

register_shutdown_function(static function (): void {
    $last = error_get_last();
    if (!$last || !in_array($last['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) return;
    $id = bin2hex(random_bytes(8));
    error_log(sprintf("[%s] [%s] [fatal] %s in %s:%d | %s %s", date('c'), $id, $last['message'],
        $last['file'], $last['line'], $_SERVER['REQUEST_METHOD'] ?? 'CLI', $_SERVER['REQUEST_URI'] ?? ''));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
        if (is_file(dirname(__DIR__) . '/500.php')) {
            if (!defined('CT4_ERROR_ID')) define('CT4_ERROR_ID', $id);
            require dirname(__DIR__) . '/500.php';
        }
    }
});
