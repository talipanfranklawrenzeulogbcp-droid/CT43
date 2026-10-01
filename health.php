<?php
declare(strict_types=1);

// Keep this endpoint completely independent of the application bootstrap,
// database, sessions, mailer, and custom error handler. Deployment systems
// use it to prove that Apache + PHP are actually serving requests.
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

http_response_code(200);

$response = [
    'status' => 'ok',
    'app' => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'checks' => [
        'php' => PHP_VERSION,
        'pdo' => extension_loaded('pdo'),
        'pdo_mysql' => extension_loaded('pdo_mysql'),
        'database' => 'not_checked',
    ],
    'timestamp' => gmdate('c'),
];

$json = json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
if ($json === false) {
    // This should never occur with the scalar values above, but still keep the
    // liveness endpoint valid if a future change introduces an encoding issue.
    echo '{"status":"ok"}';
    exit;
}

echo $json;
