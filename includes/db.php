<?php
// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// db.php — Singleton PDO connection factory.
// =============================================================
require_once __DIR__.'/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // Give deployments a useful application error instead of an opaque HTTP 500
    // when the PHP MySQL driver was not enabled by the hosting environment.
    if (!in_array('mysql', PDO::getAvailableDrivers(), true)) {
        throw new RuntimeException('The PHP PDO MySQL extension (pdo_mysql) is not enabled on this server. Enable pdo_mysql and restart PHP/Apache.');
    }

    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 8,
        ]);
    } catch (PDOException $e) {
        error_log('CT4 database connection failed: '.$e->getMessage());
        throw new RuntimeException('Unable to connect to the CT4 MySQL database. Check the database host, database name, username, password, and that MySQL is running.', 0, $e);
    }

    // First-boot recovery: if the database exists but its tables were never
    // imported, install the bundled schema once. Existing installations are
    // left untouched and continue through the lightweight migrations below.
    try {
        $tableCheck = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name='users'");
        $hasUsersTable = ((int)$tableCheck->fetchColumn()) > 0;
        if (!$hasUsersTable) {
            $schemaFile = dirname(__DIR__).'/database/database.sql';
            if (!is_readable($schemaFile)) {
                throw new RuntimeException('The CT4 database schema file is missing from the deployment.');
            }
            $schema = file_get_contents($schemaFile);
            if ($schema === false || trim($schema) === '') {
                throw new RuntimeException('The CT4 database schema file could not be read.');
            }
            $pdo->exec($schema);
        }
    } catch (Throwable $e) {
        error_log('CT4 database bootstrap failed: '.$e->getMessage());
        throw new RuntimeException('The CT4 database is reachable, but its required tables could not be initialized. Import database/database.sql or grant the database user CREATE/ALTER permissions.', 0, $e);
    }

    // Lightweight schema migrations — keep existing installations compatible.
    // All wrapped in try/catch so first-boot or managed-DB permission gaps
    // do not crash the application (HostForge migration privilege safety rule).
    try {
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS requester_user_id INT UNSIGNED NULL AFTER employee_name");
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS storage_file_id BIGINT UNSIGNED NULL AFTER file_type");
        $pdo->exec("ALTER TABLE health_safety_files ADD COLUMN IF NOT EXISTS released_at DATETIME NULL AFTER notes");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS report_name VARCHAR(120) NULL AFTER title");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS report_role VARCHAR(120) NULL AFTER report_name");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS contact_no VARCHAR(60) NULL AFTER report_role");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS compliance_note TEXT NULL AFTER contact_no");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS reported_at DATETIME NULL AFTER compliance_note");
    } catch (Throwable $e) { /* Retry on next request — initial schema may not exist yet */ }

    // Legacy roles are normalised to Staff; only Administrator and Staff are supported.
    try {
        $pdo->exec("UPDATE users SET role='Staff' WHERE role NOT IN ('Administrator','Staff')");
    } catch (Throwable $e) { /* Table may not exist during initial bootstrap */ }

    return $pdo;
}
