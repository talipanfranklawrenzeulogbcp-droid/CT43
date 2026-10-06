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
    ]);
    // Lightweight migrations keep existing installations compatible with the current interface.
    try {
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS requester_user_id INT UNSIGNED NULL AFTER employee_name");
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS storage_file_id BIGINT UNSIGNED NULL AFTER file_type");
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS released_at DATETIME NULL AFTER notes");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS report_name VARCHAR(120) NULL AFTER title");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS report_role VARCHAR(120) NULL AFTER report_name");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS contact_no VARCHAR(60) NULL AFTER report_role");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS compliance_note TEXT NULL AFTER contact_no");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS reported_at DATETIME NULL AFTER compliance_note");
    } catch (Throwable $e) { /* initial schema/migration will retry on the next request */ }
    // Legacy roles are normalized to Staff; only Administrator and Staff are supported.
    try { $pdo->exec("UPDATE users SET role='Staff' WHERE role NOT IN ('Administrator','Staff')"); } catch (Throwable $e) { /* table may not exist during initial setup */ }
    return $pdo;
}
