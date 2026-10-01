<?php
declare(strict_types=1);

/**
 * Deployment liveness endpoint.
 *
 * HostForge verifies that the container is serving HTTP by requesting this
 * file. Therefore this endpoint must not depend on MySQL or optional PHP
 * extensions. Dependency state is returned for diagnostics only.
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

$dbOk = false;
try {
    if ($checks['pdo'] && $checks['pdo_mysql']) {
        require_once __DIR__.'/includes/db.php';
        db()->query('SELECT 1');
        $dbOk = true;
    }
} catch (Throwable $e) {
    // Never expose connection details and never fail the HTTP liveness probe.
    $dbOk = false;
}

$checks['database'] = $dbOk;

// HTTP 200 means Apache + PHP are alive and able to execute this endpoint.
// Optional dependencies are reported above without blocking deployment.
http_response_code(200);

echo json_encode([
    'status' => ($dbOk && $checks['pdo_mysql'] && $checks['curl'] && $checks['mbstring'] && $checks['openssl'])
        ? 'ok'
        : 'degraded',
    'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'checks' => $checks,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
