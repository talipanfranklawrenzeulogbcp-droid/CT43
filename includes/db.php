<?php
// =============================================================
// GREAT SOLOMON MANPOWER SERVICES INC. — CORE TRANSACTION 4
// db.php — Singleton PDO connection factory.
// =============================================================
require_once __DIR__.'/config.php';

function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;

    // Fail early with a useful configuration error instead of silently falling
    // back to a local MySQL server when a deployment forgot its DB variables.
    if (APP_ENV === 'production' && (!getenv('GSMS_DB_HOST') || !getenv('GSMS_DB_NAME') || !getenv('GSMS_DB_USER'))) {
        throw new RuntimeException(
            'Database configuration is incomplete. Set GSMS_DB_HOST, GSMS_DB_NAME, GSMS_DB_USER and GSMS_DB_PASS.'
        );
    }

    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => DB_CONNECT_TIMEOUT,
        ]);
        $pdo->query('SELECT 1');
    } catch (PDOException $e) {
        // Do not expose credentials. APP_DEBUG may expose the server's normal
        // PDO message (host/DB name, but never the password) for diagnostics.
        $message = APP_DEBUG
            ? 'MySQL connection failed: '.$e->getMessage()
            : 'Database connection failed. Verify the MySQL host, port, database name, username, password, and that the database is reachable from this server.';
        throw new RuntimeException($message, (int)$e->getCode(), $e);
    }

    // Lightweight schema migrations — keep existing installations compatible.

    // WebAuthn / Face ID credential storage. Only public credential material is
    // stored; biometric templates never leave the user's device.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS webauthn_credentials (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            credential_id VARCHAR(1024) NOT NULL,
            public_key TEXT NOT NULL,
            sign_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
            transports VARCHAR(255) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            last_used_at TIMESTAMP NULL DEFAULT NULL,
            UNIQUE KEY uq_webauthn_credential (credential_id(191)),
            INDEX idx_webauthn_user (user_id),
            CONSTRAINT fk_webauthn_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        // Authentication migration: the designated CT4 administrator uses
        // password-only login and keeps the existing password hash.
        $pdo->exec("UPDATE users SET email='adminct4@mail.com' WHERE email='adminct4@gmail.com' AND NOT EXISTS (SELECT 1 FROM users WHERE email='adminct4@mail.com')");
    } catch (Throwable $e) { /* Retry on the next request if schema privileges are temporary. */ }

    // All wrapped in try/catch so first-boot or managed-DB permission gaps
    // do not crash the application (HostForge migration privilege safety rule).
    // Notification compatibility migration. Older installations may have the
    // admin_notifications table without sender_user_id, which is required to
    // route administrator feedback replies to the exact staff account.
    try {
        $pdo->exec("ALTER TABLE admin_notifications ADD COLUMN IF NOT EXISTS sender_user_id INT UNSIGNED NULL AFTER sender_role");
        $pdo->exec("ALTER TABLE admin_notifications ADD INDEX IF NOT EXISTS idx_notification_sender_user (sender_user_id)");
        $pdo->exec("ALTER TABLE admin_notifications ADD COLUMN IF NOT EXISTS reply_to_id BIGINT UNSIGNED NULL AFTER sender_user_id");
        $pdo->exec("ALTER TABLE admin_notifications ADD INDEX IF NOT EXISTS idx_notification_reply_to (reply_to_id)");
    } catch (Throwable $e) { /* Retry on the next request if the schema is still provisioning. */ }

    // Feedback management migration. This is additive and keeps the existing
    // notification records intact while introducing real feedback threads,
    // conversation messages, status, category, priority, and archival state.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_threads (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NULL,
            subject VARCHAR(180) NOT NULL DEFAULT 'General Feedback',
            category VARCHAR(60) NOT NULL DEFAULT 'General Feedback',
            priority VARCHAR(20) NOT NULL DEFAULT 'Medium',
            status VARCHAR(30) NOT NULL DEFAULT 'New',
            legacy_notification_id BIGINT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            resolved_by INT UNSIGNED NULL,
            archived_at TIMESTAMP NULL DEFAULT NULL,
            INDEX idx_feedback_user(user_id), INDEX idx_feedback_status(status),
            INDEX idx_feedback_updated(updated_at), UNIQUE KEY uq_feedback_legacy(legacy_notification_id),
            CONSTRAINT fk_feedback_thread_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT fk_feedback_thread_resolver FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS feedback_messages (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            thread_id BIGINT UNSIGNED NOT NULL,
            sender_user_id INT UNSIGNED NULL,
            sender_name VARCHAR(120) NULL,
            sender_role VARCHAR(60) NULL,
            message TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_feedback_message_thread(thread_id), INDEX idx_feedback_message_sender(sender_user_id),
            CONSTRAINT fk_feedback_message_thread FOREIGN KEY(thread_id) REFERENCES feedback_threads(id) ON DELETE CASCADE,
            CONSTRAINT fk_feedback_message_sender FOREIGN KEY(sender_user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("ALTER TABLE admin_notifications ADD COLUMN IF NOT EXISTS feedback_thread_id BIGINT UNSIGNED NULL AFTER reply_to_id");
        $pdo->exec("ALTER TABLE admin_notifications ADD INDEX IF NOT EXISTS idx_notification_feedback_thread (feedback_thread_id)");

        // Backfill legacy feedback notifications exactly once.
        $legacy = $pdo->query("SELECT id, user_id, sender_user_id, sender_name, sender_role, title, message, created_at
            FROM admin_notifications WHERE type='feedback'")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($legacy as $row) {
            $check=$pdo->prepare("SELECT id FROM feedback_threads WHERE legacy_notification_id=? LIMIT 1");
            $check->execute([(int)$row['id']]);
            $threadId=$check->fetchColumn();
            if (!$threadId) {
                $legacyUser=(int)($row['sender_user_id'] ?: 0) ?: null;
                if (!$legacyUser && trim((string)$row['sender_name'])!=='') {
                    $findUser=$pdo->prepare("SELECT id FROM users WHERE name=? AND role='Staff' AND active=1 ORDER BY id LIMIT 2");
                    $findUser->execute([trim((string)$row['sender_name'])]);
                    $matches=$findUser->fetchAll(PDO::FETCH_COLUMN);
                    if(count($matches)===1)$legacyUser=(int)$matches[0];
                }
                $ins=$pdo->prepare("INSERT INTO feedback_threads (user_id, subject, category, priority, status, legacy_notification_id, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)");
                $ins->execute([
                    $legacyUser,
                    (string)($row['title'] ?: 'General Feedback'), 'General Feedback', 'Medium', 'New',
                    (int)$row['id'], $row['created_at'], $row['created_at']
                ]);
                $threadId=(int)$pdo->lastInsertId();
                $msg=$pdo->prepare("INSERT INTO feedback_messages (thread_id,sender_user_id,sender_name,sender_role,message,created_at) VALUES (?,?,?,?,?,?)");
                $msg->execute([$threadId, (int)($row['sender_user_id'] ?: 0) ?: null, $row['sender_name'], $row['sender_role'], $row['message'], $row['created_at']]);
            }
            $link=$pdo->prepare("UPDATE admin_notifications SET feedback_thread_id=? WHERE id=? AND (feedback_thread_id IS NULL OR feedback_thread_id=0)");
            $link->execute([(int)$threadId,(int)$row['id']]);
            $replyQ=$pdo->prepare("SELECT id, user_id, sender_user_id, sender_name, sender_role, message, created_at FROM admin_notifications WHERE type='feedback_reply' AND reply_to_id=? ORDER BY id ASC");
            $replyQ->execute([(int)$row['id']]);
            foreach ($replyQ as $reply) {
                $msgCheck=$pdo->prepare("SELECT id FROM feedback_messages WHERE thread_id=? AND message=? AND created_at=? LIMIT 1");
                $msgCheck->execute([(int)$threadId,$reply['message'],$reply['created_at']]);
                if (!$msgCheck->fetchColumn()) {
                    $msg=$pdo->prepare("INSERT INTO feedback_messages (thread_id,sender_user_id,sender_name,sender_role,message,created_at) VALUES (?,?,?,?,?,?)");
                    $msg->execute([(int)$threadId,(int)($reply['sender_user_id'] ?: 0) ?: null,$reply['sender_name'],$reply['sender_role'],$reply['message'],$reply['created_at']]);
                }
                $link=$pdo->prepare("UPDATE admin_notifications SET feedback_thread_id=? WHERE id=?");
                $link->execute([(int)$threadId,(int)$reply['id']]);
            }
        }
    } catch (Throwable $e) { /* Retry on the next request if the schema is still provisioning. */ }

    // Asset picture metadata/data migration. Additive only; existing asset rows remain intact.
    try {
        $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_name VARCHAR(255) NULL AFTER location");
        $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_type VARCHAR(100) NULL AFTER image_name");
        $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_size INT UNSIGNED NOT NULL DEFAULT 0 AFTER image_type");
        $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_hash CHAR(64) NULL AFTER image_size");
        $pdo->exec("ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_data MEDIUMBLOB NULL AFTER image_hash");
    } catch (Throwable $e) { /* Retry on the next request if the schema is still provisioning. */ }

    // CT4 enhanced health, safety and compliance fields. These are additive migrations;
    // existing rows and the existing workflow remain untouched.
    try {
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS employee_id VARCHAR(60) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS department VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS position_title VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS contact_no VARCHAR(60) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS blood_type VARCHAR(10) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS emergency_contact_no VARCHAR(60) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS medical_provider VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS next_checkup_date DATE NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS work_restrictions TEXT NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS clearance_expiry DATE NULL");
        $pdo->exec("ALTER TABLE health_records ADD COLUMN IF NOT EXISTS document_ref VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS incident_type VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS location VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS reported_by VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS witnesses TEXT NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS injury_type VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS treatment TEXT NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS immediate_action TEXT NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS root_cause TEXT NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS corrective_action TEXT NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS investigation_date DATE NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS closed_date DATE NULL");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS days_lost INT UNSIGNED NOT NULL DEFAULT 0");
        $pdo->exec("ALTER TABLE safety_incidents ADD COLUMN IF NOT EXISTS external_report_ref VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS regulation_ref VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS responsible_department VARCHAR(120) NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS finding TEXT NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS evidence_reference VARCHAR(180) NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS corrective_action TEXT NULL");
        $pdo->exec("ALTER TABLE compliance_obligations ADD COLUMN IF NOT EXISTS review_date DATE NULL");
    } catch (Throwable $e) { /* Retry on next request if privileges/schema are temporarily unavailable. */ }

    // Legal & Compliance employee-document register. These tables are intentionally
    // additive so existing employee, health, safety and compliance records remain intact.
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS document_requirements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            document_name VARCHAR(180) NOT NULL,
            category VARCHAR(100) NOT NULL,
            description TEXT NULL,
            required_for_recruitment TINYINT(1) NOT NULL DEFAULT 0,
            requires_expiry TINYINT(1) NOT NULL DEFAULT 0,
            active TINYINT(1) NOT NULL DEFAULT 1,
            sort_order INT NOT NULL DEFAULT 0,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(active), INDEX(category), INDEX(sort_order)
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS employee_documents (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            employee_name VARCHAR(120) NOT NULL,
            employee_id VARCHAR(60) NULL,
            requirement_id INT UNSIGNED NULL,
            document_name VARCHAR(180) NOT NULL,
            category VARCHAR(100) NOT NULL,
            document_number VARCHAR(180) NULL,
            issued_date DATE NULL,
            expiry_date DATE NULL,
            status ENUM('Uploaded','Pending Review','Verified','Expired','Rejected') NOT NULL DEFAULT 'Pending Review',
            notes TEXT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_type VARCHAR(180) NOT NULL,
            file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
            file_hash CHAR(64) NULL,
            file_data LONGBLOB NOT NULL,
            uploaded_by INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX(employee_name), INDEX(employee_id), INDEX(requirement_id), INDEX(status), INDEX(expiry_date), INDEX(created_at),
            CONSTRAINT fk_employee_document_requirement FOREIGN KEY(requirement_id) REFERENCES document_requirements(id) ON DELETE SET NULL,
            CONSTRAINT fk_employee_document_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* Retry on the next request if the DB is still being provisioned. */ }

    // Seed only missing requirement rows; never replace or delete existing checklist data.
    try {
        $requirements = [
            ['Government-issued ID','Identity','Primary identity verification document.',1,0,10],
            ['Passport','Identity','Passport for international recruitment or deployment where applicable.',1,1,20],
            ['Birth Certificate','Identity','Identity and civil-status supporting record when required.',1,0,30],
            ['Application / Personal Data Sheet','Recruitment','Applicant information and recruitment record.',1,0,40],
            ['Resume / CV','Recruitment','Employment history, qualifications and experience.',1,0,50],
            ['Employment Contract','Employment','Signed employment terms and conditions.',1,0,60],
            ['Job Offer / Placement Agreement','Employment','Placement terms and agreed position details.',1,0,70],
            ['Diploma / Educational Certificate','Qualifications','Evidence of education or qualification where required.',0,0,80],
            ['Training Certificates','Qualifications','Relevant competency and training evidence.',0,1,90],
            ['Professional License / PRC ID','Qualifications','Professional registration or license when required for the role.',0,1,100],
            ['Medical Examination / Fit-to-Work Certificate','Health & Welfare','Pre-employment or deployment health clearance where applicable.',1,1,110],
            ['Vaccination / Immunization Record','Health & Welfare','Vaccination evidence where required by client, destination or role.',0,1,120],
            ['Health Clearance / Follow-up','Health & Welfare','Health clearance and documented follow-up requirements.',0,1,130],
            ['Emergency Contact Form','Health & Welfare','Emergency contact information for employee welfare.',1,0,140],
            ['Safety Orientation / Induction','Safety & Training','Required safety briefing or site induction record.',1,1,150],
            ['PPE / Safety Training Record','Safety & Training','Role/site-specific safety training evidence.',0,1,160],
            ['Code of Conduct Acknowledgement','Legal & Compliance','Employee acknowledgement of conduct requirements.',1,0,170],
            ['Data Privacy / Confidentiality Acknowledgement','Legal & Compliance','Privacy and confidentiality acknowledgement.',1,0,180],
            ['Client / Site Clearance','Deployment & Client','Client or worksite clearance where applicable.',0,1,190],
            ['Visa / Work Permit','Deployment & Client','Immigration or work authorization where applicable.',0,1,200],
            ['Pre-Departure / Deployment Checklist','Deployment & Client','Deployment readiness and required document confirmation.',0,0,210],
            ['Insurance / Coverage Record','Welfare & Benefits','Applicable insurance or welfare coverage evidence.',0,1,220],
        ];
        $check=$pdo->prepare('SELECT id FROM document_requirements WHERE document_name=? LIMIT 1');
        $ins=$pdo->prepare('INSERT INTO document_requirements(document_name,category,description,required_for_recruitment,requires_expiry,sort_order) VALUES(?,?,?,?,?,?)');
        foreach($requirements as $r){$check->execute([$r[0]]);if(!$check->fetchColumn())$ins->execute($r);}
    } catch (Throwable $e) { /* Requirement seeding is safe to retry. */ }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS health_followups (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            health_record_id INT UNSIGNED NULL,
            employee_name VARCHAR(120) NOT NULL,
            followup_type VARCHAR(120) NOT NULL,
            due_date DATE NOT NULL,
            status ENUM('Pending','Completed','Cancelled','Overdue') NOT NULL DEFAULT 'Pending',
            notes TEXT,
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(health_record_id), INDEX(due_date), INDEX(status),
            CONSTRAINT fk_health_followup_record FOREIGN KEY(health_record_id) REFERENCES health_records(id) ON DELETE SET NULL
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS incident_actions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            incident_id INT UNSIGNED NOT NULL,
            action_type ENUM('Immediate','Corrective','Preventive','Investigation') NOT NULL DEFAULT 'Corrective',
            action_text TEXT NOT NULL,
            owner VARCHAR(120) NULL,
            due_date DATE NULL,
            status ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(incident_id), INDEX(due_date), INDEX(status),
            CONSTRAINT fk_incident_action_incident FOREIGN KEY(incident_id) REFERENCES safety_incidents(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
        $pdo->exec("CREATE TABLE IF NOT EXISTS compliance_action_items (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            compliance_id INT UNSIGNED NOT NULL,
            action_text TEXT NOT NULL,
            owner VARCHAR(120) NULL,
            due_date DATE NULL,
            status ENUM('Open','In Progress','Completed','Cancelled') NOT NULL DEFAULT 'Open',
            completion_notes TEXT,
            completed_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX(compliance_id), INDEX(due_date), INDEX(status),
            CONSTRAINT fk_compliance_action_item FOREIGN KEY(compliance_id) REFERENCES compliance_obligations(id) ON DELETE CASCADE
        ) ENGINE=InnoDB");
    } catch (Throwable $e) { /* First boot may occur before the base tables exist. */ }

    try {
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
