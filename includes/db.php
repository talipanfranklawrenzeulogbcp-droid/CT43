<?php
require_once __DIR__ . '/error_handler.php';

// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// db.php — Singleton PDO connection factory.
// =============================================================
require_once __DIR__.'/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'The PHP PDO MySQL extension (pdo_mysql) is not enabled on this server. ' .
            'Enable/install pdo_mysql, then restart PHP/Apache.'
        );
    }

    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    } catch (PDOException $e) {
        error_log('CT4 database connection failed: '.$e->getMessage());
        throw new RuntimeException(
            'Database connection failed. Check the MySQL service and GSMS_DB_HOST, GSMS_DB_PORT, ' .
            'GSMS_DB_NAME, GSMS_DB_USER, and GSMS_DB_PASS settings.'
        );
    }

    // Schema migrations are intentionally NOT run on every request.
    // The shipped database/database.sql contains the current schema. Existing
    // installations should run that SQL migration once during deployment.
    // This keeps normal page loads free of repeated ALTER TABLE metadata locks.
    // Legacy roles are normalised only when the users table is available.
    static $rolesChecked = false;
    if (!$rolesChecked) {
        $rolesChecked = true;
        try {
            $pdo->exec("UPDATE users SET role='Staff' WHERE role NOT IN ('Administrator','Staff')");
        } catch (Throwable $e) {
            // Initial database import may still be in progress.
        }
    }

    return $pdo;
}
