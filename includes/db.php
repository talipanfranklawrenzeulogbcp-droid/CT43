<?php
require_once __DIR__.'/config.php';
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => DB_CONNECT_TIMEOUT,
    ]);
    // Schema is provisioned from database/database.sql during deployment.
    // Do not run ALTER/UPDATE migrations on every web request; doing so can
    // hold the first request open and make health/deployment checks appear slow.
    return $pdo;
}
