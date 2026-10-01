-- CT4 one-time migration for an EXISTING installation.
-- Run this once against the existing CT4 database before deploying the new PHP code.
-- Do not run this file repeatedly.

ALTER TABLE admin_notifications ADD COLUMN sender_user_id INT UNSIGNED NULL AFTER sender_role;
ALTER TABLE admin_notifications ADD INDEX idx_notification_sender_user (sender_user_id);
ALTER TABLE assets ADD COLUMN quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number;
ALTER TABLE health_safety_files ADD COLUMN requester_user_id INT UNSIGNED NULL AFTER employee_name;
ALTER TABLE health_safety_files ADD COLUMN storage_file_id BIGINT UNSIGNED NULL AFTER file_type;
ALTER TABLE health_safety_files ADD COLUMN released_at DATETIME NULL AFTER notes;
ALTER TABLE compliance_obligations ADD COLUMN report_name VARCHAR(120) NULL AFTER title;
ALTER TABLE compliance_obligations ADD COLUMN report_role VARCHAR(120) NULL AFTER report_name;
ALTER TABLE compliance_obligations ADD COLUMN contact_no VARCHAR(60) NULL AFTER report_role;
ALTER TABLE compliance_obligations ADD COLUMN compliance_note TEXT NULL AFTER contact_no;
ALTER TABLE compliance_obligations ADD COLUMN reported_at DATETIME NULL AFTER compliance_note;

ALTER TABLE safety_incidents ADD INDEX idx_safety_incident_date_status (incident_date,status);
ALTER TABLE health_records ADD INDEX idx_health_checkup_date (checkup_date);
ALTER TABLE compliance_obligations ADD INDEX idx_compliance_due_status (due_date,status);
ALTER TABLE compliance_audits ADD INDEX idx_compliance_audit_date_status (audit_date,status);
ALTER TABLE asset_issuances ADD INDEX idx_asset_issuance_status_return (status,return_date);
ALTER TABLE asset_issuances ADD INDEX idx_asset_issuance_expected_return (expected_return);
