<?php
/**
 * Core Transaction 4 liveness/diagnostic endpoint.
 *
 * IMPORTANT:
 * This endpoint intentionally does not require the database to be available.
 * Deployment platforms use it to determine whether the PHP/Apache application
 * is serving HTTP successfully. Database readiness is reported as diagnostic
 * information, but must not turn the liveness endpoint into HTTP 500/503.
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');

$checks = [
    'php' => PHP_VERSION,
    'pdo' => extension_loaded('pdo'),
    'pdo_mysql' => extension_loaded('pdo_mysql'),
];

// Liveness must not depend on external services. We only report whether the
// PHP extensions needed by the application are loaded. A separate readiness
// check can validate the database after the deployment is live.
$checks['database'] = 'not_checked';
$checks['environment'] = [
    'database_host_configured' => (bool)(getenv('GSMS_DB_HOST') ?: getenv('DB_HOST') ?: getenv('DATABASE_URL')),
    'database_name_configured' => (bool)(getenv('GSMS_DB_NAME') ?: getenv('DB_DATABASE') ?: getenv('DATABASE_URL')),
];

// HTTP 200 is intentional: this is a liveness endpoint for deployment.
// Consumers that need database readiness should inspect checks.database or
// use a separate readiness check after infrastructure provisioning.
http_response_code(200);

echo json_encode([
    'status' => 'ok',
    'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'checks' => $checks,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
