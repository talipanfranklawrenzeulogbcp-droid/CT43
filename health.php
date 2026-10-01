<?php
declare(strict_types=1);

/**
 * Deployment/runtime health endpoint.
 * Does not expose credentials or database details.
 */
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$checks = [
    'php' => PHP_VERSION,
    'pdo' => extension_loaded('pdo'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
    'curl' => extension_loaded('curl'),
    'mbstring' => extension_loaded('mbstring'),
    'openssl' => extension_loaded('openssl'),
];

$ok = !in_array(false, $checks, true);
$dbOk = false;

try {
    require_once __DIR__.'/includes/db.php';
    db()->query('SELECT 1');
    $dbOk = true;
} catch (Throwable $e) {
    // Never return connection details to the public health endpoint.
    $dbOk = false;
}

$checks['database'] = $dbOk;
$ok = $ok && $dbOk;

http_response_code($ok ? 200 : 503);

echo json_encode([
    'status' => $ok ? 'ok' : 'degraded',
    'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'checks' => $checks,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES);
