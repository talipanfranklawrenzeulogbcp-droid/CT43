<?php
declare(strict_types=1);

// Basic web health remains independent of MySQL so a database outage does not
// make the container look dead to the platform. Add ?db=1 to verify MySQL.
$checkDb = isset($_GET['db']) && $_GET['db'] === '1';
$dbStatus = null;
$dbError = null;

if ($checkDb) {
    try {
        require_once __DIR__.'/includes/db.php';
        $pdo = db();
        $pdo->query('SELECT 1')->fetchColumn();
        $dbStatus = 'ok';
    } catch (Throwable $e) {
        $dbStatus = 'error';
        $dbError = APP_DEBUG ? $e->getMessage() : 'Database connection failed';
    }
}

http_response_code(($checkDb && $dbStatus !== 'ok') ? 503 : 200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

$response = [
    'status'    => ($checkDb && $dbStatus !== 'ok') ? 'degraded' : 'ok',
    'app'       => 'Great Solomon Manpower Services Inc. Core Transaction 4',
    'timestamp' => date('c'),
];
if ($checkDb) {
    $response['database'] = $dbStatus;
    if ($dbError !== null) $response['database_error'] = $dbError;
}

echo json_encode($response, JSON_UNESCAPED_SLASHES);
