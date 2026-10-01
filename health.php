<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$checks = [
    'php' => PHP_VERSION,
    'pdo' => extension_loaded('pdo'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
];

$ok = $checks['pdo'] && $checks['pdo_mysql'];

if ($ok) {
    require_once __DIR__.'/includes/config.php';
    try {
        require_once __DIR__.'/includes/db.php';
        db()->query('SELECT 1');
        $checks['database'] = 'connected';
    } catch (Throwable $e) {
        $checks['database'] = 'unavailable';
        $checks['database_error'] = $e->getMessage();
        $ok = false;
    }
} else {
    $checks['database'] = 'not tested';
}

http_response_code($ok ? 200 : 503);
echo json_encode([
    'status' => $ok ? 'ok' : 'degraded',
    'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'checks' => $checks,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES);
?>
