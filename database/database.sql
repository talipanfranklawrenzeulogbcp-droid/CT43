-- Optional for local setup only (uncomment if creating a local database from scratch):
-- CREATE DATABASE IF NOT EXISTS great_solomon_ct4 CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- USE great_solomon_ct4;

CREATE TABLE IF NOT EXISTS users (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(120) NOT NULL,
 email VARCHAR(190) NOT NULL UNIQUE,
 password_hash VARCHAR(255) NOT NULL,
 role VARCHAR(60) NOT NULL DEFAULT 'Staff',
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
 ) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS admin_notifications (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 type VARCHAR(40) NOT NULL DEFAULT 'feedback',
 title VARCHAR(180) NOT NULL,
 message TEXT NOT NULL,
 sender_name VARCHAR(120) NULL,
 sender_role VARCHAR(60) NULL,
 sender_user_id INT UNSIGNED NULL,
 is_read TINYINT(1) NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id), INDEX(type), INDEX(is_read), INDEX(created_at),
 CONSTRAINT fk_notification_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
-- CT4 Feedback conversations: additive thread reference.
-- Existing feedback/reply rows are preserved; new replies are linked to the
-- original feedback notification through reply_to_id.
ALTER TABLE admin_notifications
  ADD COLUMN IF NOT EXISTS reply_to_id BIGINT UNSIGNED NULL AFTER sender_user_id;
ALTER TABLE admin_notifications
  ADD INDEX IF NOT EXISTS idx_notification_reply_to (reply_to_id);
ALTER TABLE admin_notifications
  ADD COLUMN IF NOT EXISTS feedback_thread_id BIGINT UNSIGNED NULL AFTER reply_to_id;
ALTER TABLE admin_notifications
  ADD INDEX IF NOT EXISTS idx_notification_feedback_thread (feedback_thread_id);

CREATE TABLE IF NOT EXISTS feedback_threads (
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
 INDEX idx_feedback_user(user_id), INDEX idx_feedback_status(status), INDEX idx_feedback_updated(updated_at),
 UNIQUE KEY uq_feedback_legacy(legacy_notification_id),
 CONSTRAINT fk_feedback_thread_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL,
 CONSTRAINT fk_feedback_thread_resolver FOREIGN KEY(resolved_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS feedback_messages (
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
) ENGINE=InnoDB;

-- Backfill feedback created by the earlier notification-only version into the
-- dedicated conversation tables. These statements are idempotent and preserve
-- the original notification records.
INSERT INTO feedback_threads
  (user_id, subject, category, priority, status, legacy_notification_id, created_at, updated_at)
SELECT
  n.sender_user_id,
  COALESCE(NULLIF(TRIM(n.title),''),'General Feedback'),
  'General Feedback',
  'Medium',
  'New',
  n.id,
  n.created_at,
  n.created_at
FROM admin_notifications n
LEFT JOIN feedback_threads t ON t.legacy_notification_id=n.id
WHERE n.type='feedback'
  AND n.feedback_thread_id IS NULL
  AND t.id IS NULL;

INSERT INTO feedback_messages
  (thread_id, sender_user_id, sender_name, sender_role, message, created_at)
SELECT
  t.id, n.sender_user_id, n.sender_name, n.sender_role, n.message, n.created_at
FROM admin_notifications n
JOIN feedback_threads t ON t.legacy_notification_id=n.id
WHERE n.type='feedback'
  AND NOT EXISTS (
    SELECT 1 FROM feedback_messages m
    WHERE m.thread_id=t.id
      AND m.message=n.message
      AND m.created_at=n.created_at
      AND COALESCE(m.sender_user_id,0)=COALESCE(n.sender_user_id,0)
  );

UPDATE admin_notifications n
JOIN feedback_threads t ON t.legacy_notification_id=n.id
SET n.feedback_thread_id=t.id
WHERE n.type='feedback' AND n.feedback_thread_id IS NULL;

INSERT INTO feedback_messages
  (thread_id, sender_user_id, sender_name, sender_role, message, created_at)
SELECT
  t.id, n.sender_user_id, n.sender_name, n.sender_role, n.message, n.created_at
FROM admin_notifications n
JOIN feedback_threads t ON t.legacy_notification_id=n.reply_to_id
WHERE n.type='feedback_reply'
  AND n.feedback_thread_id IS NULL
  AND NOT EXISTS (
    SELECT 1 FROM feedback_messages m
    WHERE m.thread_id=t.id
      AND m.message=n.message
      AND m.created_at=n.created_at
      AND COALESCE(m.sender_user_id,0)=COALESCE(n.sender_user_id,0)
  );

UPDATE admin_notifications n
JOIN feedback_threads t ON t.legacy_notification_id=n.reply_to_id
SET n.feedback_thread_id=t.id
WHERE n.type='feedback_reply' AND n.feedback_thread_id IS NULL;

UPDATE feedback_threads t
JOIN admin_notifications r ON r.feedback_thread_id=t.id AND r.type='feedback_reply'
SET t.status='Replied', t.updated_at=GREATEST(t.updated_at,r.created_at)
WHERE t.status='New';

CREATE TABLE IF NOT EXISTS archive_items (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 item_type VARCHAR(40) NOT NULL,
 source_table VARCHAR(120) NOT NULL,
 source_id BIGINT UNSIGNED NULL,
 item_name VARCHAR(255) NOT NULL,
 payload LONGTEXT NOT NULL,
 file_data LONGBLOB NULL,
 file_type VARCHAR(180) NULL,
 deleted_by INT UNSIGNED NULL,
 deleted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(item_type), INDEX(source_table), INDEX(source_id), INDEX(deleted_at),
 CONSTRAINT fk_archive_user FOREIGN KEY(deleted_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS audit_logs (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 module VARCHAR(120) NOT NULL,
 action VARCHAR(120) NOT NULL,
 details TEXT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id), INDEX(module), INDEX(created_at),
 CONSTRAINT fk_audit_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS login_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 email VARCHAR(190) NOT NULL,
 status ENUM('Success','Failed','OTP Pending') NOT NULL,
 ip_address VARCHAR(45) NULL,
 user_agent VARCHAR(500) NULL,
 login_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id), INDEX(email), INDEX(login_at),
 CONSTRAINT fk_login_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS otp_requests (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NOT NULL,
 otp_hash VARCHAR(255) NOT NULL,
 expires_at DATETIME NOT NULL,
 attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(user_id), INDEX(expires_at), INDEX(created_at),
 CONSTRAINT fk_otp_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS safety_incidents (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(180) NOT NULL,
 employee_name VARCHAR(120) NOT NULL,
 incident_date DATE NOT NULL,
 severity ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
 status ENUM('Open','Under Investigation','Closed') NOT NULL DEFAULT 'Open',
 description TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS health_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_name VARCHAR(120) NOT NULL,
 checkup_date DATE NOT NULL,
 record_type VARCHAR(120),
 fitness_status ENUM('Fit','Fit with Restrictions','Unfit','Pending') NOT NULL DEFAULT 'Pending',
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS compliance_obligations (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(180) NOT NULL,
 report_name VARCHAR(120) NULL,
 report_role VARCHAR(120) NULL,
 contact_no VARCHAR(60) NULL,
 compliance_note TEXT NULL,
 reported_at DATETIME NULL,
 category VARCHAR(120),
 owner VARCHAR(120),
 due_date DATE NOT NULL,
 priority ENUM('Low','Medium','High','Critical') NOT NULL DEFAULT 'Medium',
 status ENUM('Open','In Progress','Compliant','Overdue') NOT NULL DEFAULT 'Open',
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS compliance_audits (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 title VARCHAR(180) NOT NULL,
 audit_date DATE NOT NULL,
 auditor VARCHAR(120),
 status ENUM('Scheduled','In Progress','Completed','Closed') NOT NULL DEFAULT 'Scheduled',
 findings TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS security_events (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 user_id INT UNSIGNED NULL,
 event_type VARCHAR(100) NOT NULL,
 severity VARCHAR(30) NOT NULL DEFAULT 'Info',
 description TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(event_type), INDEX(severity), INDEX(created_at),
 CONSTRAINT fk_security_user FOREIGN KEY(user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS assets (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_tag VARCHAR(80) NOT NULL UNIQUE,
 name VARCHAR(180) NOT NULL,
 category VARCHAR(100),
 serial_number VARCHAR(120),
 quantity INT UNSIGNED NOT NULL DEFAULT 1,
 status ENUM('Available','Issued','Maintenance','Retired') NOT NULL DEFAULT 'Available',
 location VARCHAR(180),
 image_name VARCHAR(255) NULL,
 image_type VARCHAR(100) NULL,
 image_size INT UNSIGNED NOT NULL DEFAULT 0,
 image_hash CHAR(64) NULL,
 image_data MEDIUMBLOB NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
-- Migrations for existing installations; all are additive and preserve existing asset records.
ALTER TABLE assets ADD COLUMN IF NOT EXISTS quantity INT UNSIGNED NOT NULL DEFAULT 1 AFTER serial_number;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_name VARCHAR(255) NULL AFTER location;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_type VARCHAR(100) NULL AFTER image_name;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_size INT UNSIGNED NOT NULL DEFAULT 0 AFTER image_type;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_hash CHAR(64) NULL AFTER image_size;
ALTER TABLE assets ADD COLUMN IF NOT EXISTS image_data MEDIUMBLOB NULL AFTER image_hash;
CREATE TABLE IF NOT EXISTS asset_issuances (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_id INT UNSIGNED NOT NULL,
 employee_name VARCHAR(120) NOT NULL,
 issued_date DATE NOT NULL,
 expected_return DATE NULL,
 return_date DATE NULL,
 status ENUM('Issued','Returned','Overdue','Not Returned') NOT NULL DEFAULT 'Issued',
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_issuance_asset FOREIGN KEY(asset_id) REFERENCES assets(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
CREATE TABLE IF NOT EXISTS maintenance_records (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 asset_id INT UNSIGNED NOT NULL,
 service_date DATE NOT NULL,
 service_type VARCHAR(120),
 cost DECIMAL(12,2) DEFAULT 0,
 status VARCHAR(60) DEFAULT 'Completed',
 notes TEXT,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 CONSTRAINT fk_maintenance_asset FOREIGN KEY(asset_id) REFERENCES assets(id) ON DELETE CASCADE
) ENGINE=InnoDB;

UPDATE users SET role='Staff' WHERE role NOT IN ('Administrator','Staff');

INSERT INTO users(name,email,password_hash,role,active) VALUES
('Admin','adminct4@gmail.com','$2y$12$W3CxFvVU6NqcmG5VEempMeY4/gfboeUJdjQgxrzfLtgNCiFpDShQu','Administrator',1)
ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role='Administrator', active=1;

INSERT INTO users(name,email,password_hash,role,active) VALUES
('Staff','ct4staff@gmail.com','$2y$12$W3CxFvVU6NqcmG5VEempMeY4/gfboeUJdjQgxrzfLtgNCiFpDShQu','Staff',1)
ON DUPLICATE KEY UPDATE name=VALUES(name), password_hash=VALUES(password_hash), role='Staff', active=1;

INSERT INTO safety_incidents(title,employee_name,incident_date,severity,status,description) VALUES
('Safety inspection finding','Juan Dela Cruz',CURDATE(),'Medium','Open','Initial sample incident for the database.'),
('Minor workplace injury','Maria Santos',DATE_SUB(CURDATE(),INTERVAL 2 DAY),'Low','Closed','Sample closed incident.');
INSERT INTO health_records(employee_name,checkup_date,record_type,fitness_status,notes) VALUES
('Juan Dela Cruz',CURDATE(),'Annual Checkup','Fit','Sample health record.');
INSERT INTO compliance_obligations(title,category,owner,due_date,priority,status) VALUES
('Annual workplace compliance review','Regulatory','Compliance Officer',DATE_ADD(CURDATE(),INTERVAL 30 DAY),'High','Open'),
('Permit renewal','Permit','Administration',DATE_ADD(CURDATE(),INTERVAL 10 DAY),'Medium','In Progress');
INSERT INTO compliance_audits(title,audit_date,auditor,status,findings) VALUES
('Annual compliance audit',DATE_ADD(CURDATE(),INTERVAL 7 DAY),'Internal Audit','Scheduled','Sample audit schedule.');
INSERT INTO assets(asset_tag,name,category,serial_number,status,quantity,location) VALUES
('AST-0001','Laptop - Admin','Computer','SN-GSMS-0001','Issued',1,'Head Office'),
('AST-0002','Desktop Workstation','Computer','SN-GSMS-0002','Available',1,'Head Office'),
('AST-0003','Network Printer','Printer','SN-GSMS-0003','Maintenance',1,'IT Room')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO asset_issuances(asset_id,employee_name,issued_date,expected_return,status,notes) VALUES
(1,'Admin User',CURDATE(),DATE_ADD(CURDATE(),INTERVAL 365 DAY),'Issued','Sample issuance.');
UPDATE assets SET status='Issued' WHERE asset_tag='AST-0001';

-- Additional demo issuance history records
INSERT INTO assets(asset_tag,name,category,serial_number,status,quantity,location) VALUES
('AST-0004','Company Tablet','Mobile Device','SN-GSMS-0004','Available',1,'Head Office'),
('AST-0005','Projector','Presentation','SN-GSMS-0005','Issued',1,'Training Room'),
('AST-0006','Office Laptop','Computer','SN-GSMS-0006','Available',1,'Operations')
ON DUPLICATE KEY UPDATE name=VALUES(name);
INSERT INTO asset_issuances(asset_id,employee_name,issued_date,expected_return,status,notes)
SELECT id,'Maria Santos',DATE_SUB(CURDATE(),INTERVAL 5 DAY),DATE_ADD(CURDATE(),INTERVAL 10 DAY),'Returned','Demo returned issuance.'
FROM assets WHERE asset_tag='AST-0004'
AND NOT EXISTS (SELECT 1 FROM asset_issuances i WHERE i.asset_id=assets.id AND i.employee_name='Maria Santos');
INSERT INTO asset_issuances(asset_id,employee_name,issued_date,expected_return,status,notes)
SELECT id,'Juan Dela Cruz',DATE_SUB(CURDATE(),INTERVAL 3 DAY),DATE_ADD(CURDATE(),INTERVAL 7 DAY),'Not Returned','Demo active issuance.'
FROM assets WHERE asset_tag='AST-0005'
AND NOT EXISTS (SELECT 1 FROM asset_issuances i WHERE i.asset_id=assets.id AND i.employee_name='Juan Dela Cruz');

UPDATE assets SET status='Issued' WHERE asset_tag='AST-0005';

-- CT4 Data Storage: files/data received from other branches
CREATE TABLE IF NOT EXISTS data_storage (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 file_name VARCHAR(255) NOT NULL,
 file_type VARCHAR(150) NOT NULL DEFAULT 'application/octet-stream',
 file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 source_branch VARCHAR(180) NULL,
 uploaded_by INT UNSIGNED NULL,
 file_data LONGBLOB NOT NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 INDEX(uploaded_by), INDEX(created_at),
 CONSTRAINT fk_storage_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;


-- CT4 Enhanced Health, Safety & Compliance fields.
-- Existing records are preserved; these ALTER statements only add nullable fields.
ALTER TABLE health_records
  ADD COLUMN IF NOT EXISTS employee_id VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS department VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS position_title VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS contact_no VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS blood_type VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS emergency_contact VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS emergency_contact_no VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS medical_provider VARCHAR(180) NULL,
  ADD COLUMN IF NOT EXISTS next_checkup_date DATE NULL,
  ADD COLUMN IF NOT EXISTS work_restrictions TEXT NULL,
  ADD COLUMN IF NOT EXISTS clearance_expiry DATE NULL,
  ADD COLUMN IF NOT EXISTS document_ref VARCHAR(180) NULL;

ALTER TABLE safety_incidents
  ADD COLUMN IF NOT EXISTS incident_type VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS location VARCHAR(180) NULL,
  ADD COLUMN IF NOT EXISTS reported_by VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS witnesses TEXT NULL,
  ADD COLUMN IF NOT EXISTS injury_type VARCHAR(180) NULL,
  ADD COLUMN IF NOT EXISTS treatment TEXT NULL,
  ADD COLUMN IF NOT EXISTS immediate_action TEXT NULL,
  ADD COLUMN IF NOT EXISTS root_cause TEXT NULL,
  ADD COLUMN IF NOT EXISTS corrective_action TEXT NULL,
  ADD COLUMN IF NOT EXISTS investigation_date DATE NULL,
  ADD COLUMN IF NOT EXISTS closed_date DATE NULL,
  ADD COLUMN IF NOT EXISTS days_lost INT UNSIGNED NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS external_report_ref VARCHAR(180) NULL;

ALTER TABLE compliance_obligations
  ADD COLUMN IF NOT EXISTS regulation_ref VARCHAR(180) NULL,
  ADD COLUMN IF NOT EXISTS responsible_department VARCHAR(120) NULL,
  ADD COLUMN IF NOT EXISTS finding TEXT NULL,
  ADD COLUMN IF NOT EXISTS evidence_reference VARCHAR(180) NULL,
  ADD COLUMN IF NOT EXISTS corrective_action TEXT NULL,
  ADD COLUMN IF NOT EXISTS review_date DATE NULL;

CREATE TABLE IF NOT EXISTS health_followups (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS incident_actions (
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
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS compliance_action_items (
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
) ENGINE=InnoDB;

-- CT4 Employee Legal & Compliance Document Register.
-- Additive only: existing records are preserved.
CREATE TABLE IF NOT EXISTS document_requirements (
 id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 document_name VARCHAR(180) NOT NULL,
 category VARCHAR(120) NOT NULL,
 description TEXT NULL,
 required_for_recruitment TINYINT(1) NOT NULL DEFAULT 1,
 requires_expiry TINYINT(1) NOT NULL DEFAULT 0,
 sort_order INT NOT NULL DEFAULT 0,
 active TINYINT(1) NOT NULL DEFAULT 1,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY uq_document_requirement_name (document_name),
 INDEX(category), INDEX(active), INDEX(sort_order)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS employee_documents (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 employee_name VARCHAR(120) NOT NULL,
 employee_id VARCHAR(60) NULL,
 requirement_id INT UNSIGNED NULL,
 document_name VARCHAR(180) NOT NULL,
 category VARCHAR(120) NOT NULL,
 document_number VARCHAR(180) NULL,
 issued_date DATE NULL,
 expiry_date DATE NULL,
 status ENUM('Uploaded','Pending Review','Verified','Expired','Rejected') NOT NULL DEFAULT 'Pending Review',
 notes TEXT NULL,
 file_name VARCHAR(255) NOT NULL,
 file_type VARCHAR(180) NOT NULL DEFAULT 'application/octet-stream',
 file_size BIGINT UNSIGNED NOT NULL DEFAULT 0,
 file_hash CHAR(64) NULL,
 file_data LONGBLOB NOT NULL,
 uploaded_by INT UNSIGNED NULL,
 created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 INDEX(employee_name), INDEX(employee_id), INDEX(requirement_id), INDEX(category), INDEX(status), INDEX(expiry_date), INDEX(created_at),
 CONSTRAINT fk_employee_document_requirement FOREIGN KEY(requirement_id) REFERENCES document_requirements(id) ON DELETE SET NULL,
 CONSTRAINT fk_employee_document_user FOREIGN KEY(uploaded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

INSERT INTO document_requirements(document_name,category,description,required_for_recruitment,requires_expiry,sort_order) VALUES
('Valid Government-Issued ID','Identity','Passport, national ID, driver license, or other accepted government-issued identification.',1,1,10),
('Passport','Identity & Deployment','Passport copy for overseas deployment or where required by client/country.',0,1,20),
('Birth Certificate','Identity & Civil Records','Civil identity and date-of-birth supporting document where required.',1,0,30),
('NBI / Police Clearance','Background & Screening','Background screening record required by the role, client, or applicable process.',1,1,40),
('Employment Application / Personal Data Sheet','Recruitment','Completed recruitment and employee information form.',1,0,50),
('Resume / Curriculum Vitae','Recruitment','Current employment history, skills, experience, and qualifications.',1,0,60),
('Employment Contract','Employment','Signed employment agreement and applicable terms and conditions.',1,0,70),
('Job Offer / Placement Agreement','Employment','Offer, placement, or deployment agreement where applicable.',1,0,80),
('Educational Certificate / Diploma','Qualifications','Proof of required educational attainment.',1,0,90),
('Training Certificates','Qualifications & Training','Certificates supporting role-specific technical or professional training.',1,1,100),
('Professional License / PRC ID','Qualifications & Licensing','Professional license or registration where the position requires one.',0,1,110),
('Medical Examination / Fit-to-Work Certificate','Health & Welfare','Medical fitness documentation and work clearance, subject to applicable privacy requirements.',1,1,120),
('Vaccination / Immunization Record','Health & Welfare','Vaccination evidence only when required by law, client, destination, or role.',0,1,130),
('Health Clearance / Medical Follow-up','Health & Welfare','Follow-up clearance, restrictions, or return-to-work documentation when applicable.',0,1,140),
('Safety Orientation / Induction Record','Safety & Training','Evidence that required workplace safety orientation or induction was completed.',1,1,150),
('PPE / Safety Training Record','Safety & Training','Role-specific PPE, emergency, or safety training record.',0,1,160),
('Code of Conduct / Policy Acknowledgement','Compliance','Signed acknowledgement of company policies and required conduct standards.',1,0,170),
('Data Privacy / Confidentiality Acknowledgement','Compliance','Acknowledgement of applicable privacy, confidentiality, and information-handling obligations.',1,0,180),
('Client / Site Clearance','Deployment & Client','Client, site, access, or security clearance where required for deployment.',0,1,190),
('Visa / Work Permit','Deployment & Client','Visa, work permit, or immigration document for applicable overseas assignments.',0,1,200),
('Pre-Departure / Deployment Checklist','Deployment & Client','Completed deployment readiness checklist and supporting confirmations.',0,1,210),
('Insurance / Coverage Record','Welfare & Benefits','Applicable employee insurance or coverage record.',0,1,220),
('Emergency Contact Form','Health & Welfare','Current emergency contact information for employee welfare and incident response.',1,0,230)
ON DUPLICATE KEY UPDATE category=VALUES(category),description=VALUES(description),required_for_recruitment=VALUES(required_for_recruitment),requires_expiry=VALUES(requires_expiry),sort_order=VALUES(sort_order),active=1;

-- =============================================================
-- CT4 DEMO DATA PACK
-- Adds realistic fictional sample records and completes blank
-- enhanced fields on existing records. Idempotent by stable keys.
-- =============================================================

-- Fill existing Health records without overwriting populated values.
UPDATE health_records
SET
 employee_id = COALESCE(NULLIF(employee_id,''), CONCAT('EMP-', LPAD(id,4,'0'))),
 department = COALESCE(NULLIF(department,''), 'Operations'),
 position_title = COALESCE(NULLIF(position_title,''), 'Operations Staff'),
 contact_no = COALESCE(NULLIF(contact_no,''), CONCAT('0917-555-', LPAD(MOD(id,9000)+1000,4,'0'))),
 blood_type = COALESCE(NULLIF(blood_type,''), 'O+'),
 emergency_contact = COALESCE(NULLIF(emergency_contact,''), 'Emergency Contact'),
 emergency_contact_no = COALESCE(NULLIF(emergency_contact_no,''), '0918-555-0101'),
 medical_provider = COALESCE(NULLIF(medical_provider,''), 'CT4 Partner Clinic'),
 next_checkup_date = COALESCE(next_checkup_date, DATE_ADD(checkup_date, INTERVAL 1 YEAR)),
 work_restrictions = COALESCE(NULLIF(work_restrictions,''), 'None'),
 clearance_expiry = COALESCE(clearance_expiry, DATE_ADD(checkup_date, INTERVAL 1 YEAR)),
 document_ref = COALESCE(NULLIF(document_ref,''), CONCAT('MED-', DATE_FORMAT(checkup_date,'%Y%m%d'), '-', id))
WHERE 1=1;

-- Fill existing Safety Incident enhanced fields.
UPDATE safety_incidents
SET
 incident_type = COALESCE(NULLIF(incident_type,''), 'Workplace Safety Observation'),
 location = COALESCE(NULLIF(location,''), 'Head Office'),
 reported_by = COALESCE(NULLIF(reported_by,''), 'Safety Officer'),
 witnesses = COALESCE(NULLIF(witnesses,''), 'No additional witnesses recorded'),
 injury_type = COALESCE(NULLIF(injury_type,''), CASE WHEN severity IN ('High','Critical') THEN 'Injury requiring assessment' ELSE 'No injury reported' END),
 treatment = COALESCE(NULLIF(treatment,''), CASE WHEN injury_type IS NULL OR injury_type='' THEN 'First aid assessment / observation' ELSE 'First aid assessment' END),
 immediate_action = COALESCE(NULLIF(immediate_action,''), 'Area checked and immediate hazard controlled.'),
 root_cause = COALESCE(NULLIF(root_cause,''), 'Under review / routine safety assessment.'),
 corrective_action = COALESCE(NULLIF(corrective_action,''), 'Conduct refresher briefing and monitor the work area.'),
 investigation_date = COALESCE(investigation_date, incident_date),
 closed_date = CASE WHEN status='Closed' THEN COALESCE(closed_date, incident_date) ELSE closed_date END,
 external_report_ref = COALESCE(NULLIF(external_report_ref,''), CONCAT('INC-', DATE_FORMAT(incident_date,'%Y%m%d'), '-', id))
WHERE 1=1;

-- Fill existing Compliance Obligation enhanced fields.
UPDATE compliance_obligations
SET
 regulation_ref = COALESCE(NULLIF(regulation_ref,''), CONCAT('CT4-', UPPER(REPLACE(COALESCE(category,'GENERAL'),' ','-')), '-', id)),
 responsible_department = COALESCE(NULLIF(responsible_department,''), COALESCE(owner,'Administration')),
 finding = COALESCE(NULLIF(finding,''), CASE WHEN status='Compliant' THEN 'No outstanding finding recorded.' ELSE 'Monitoring and supporting evidence are required.' END),
 evidence_reference = COALESCE(NULLIF(evidence_reference,''), CONCAT('EVID-', LPAD(id,4,'0'))),
 corrective_action = COALESCE(NULLIF(corrective_action,''), CASE WHEN status='Compliant' THEN 'Maintain current controls and retain evidence.' ELSE 'Collect required evidence and complete the compliance action.' END),
 review_date = COALESCE(review_date, due_date)
WHERE 1=1;

-- 10 fictional Health Records.
INSERT INTO health_records
(employee_name,checkup_date,record_type,fitness_status,notes,employee_id,department,position_title,contact_no,blood_type,emergency_contact,emergency_contact_no,medical_provider,next_checkup_date,work_restrictions,clearance_expiry,document_ref)
SELECT * FROM (
 SELECT 'Ana Reyes',DATE_SUB(CURDATE(),INTERVAL 12 DAY),'Annual Medical Checkup','Fit','Routine annual assessment; no restrictions.','EMP-1001','Recruitment','Recruitment Officer','0917-555-1001','O+','Liza Reyes','0918-555-1001','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 353 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 353 DAY),'MED-1001' UNION ALL
 SELECT 'Ben Garcia',DATE_SUB(CURDATE(),INTERVAL 25 DAY),'Pre-Employment Examination','Fit','Cleared for assigned duties.','EMP-1002','Operations','Operations Coordinator','0917-555-1002','A+','Ramon Garcia','0918-555-1002','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 340 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 340 DAY),'MED-1002' UNION ALL
 SELECT 'Carla Mendoza',DATE_SUB(CURDATE(),INTERVAL 40 DAY),'Annual Medical Checkup','Fit with Restrictions','Temporary lifting restriction recorded.','EMP-1003','Health & Safety','Safety Assistant','0917-555-1003','B+','Nina Mendoza','0918-555-1003','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 325 DAY),'Avoid lifting above 10 kg until review.',DATE_ADD(CURDATE(),INTERVAL 325 DAY),'MED-1003' UNION ALL
 SELECT 'Daniel Cruz',DATE_SUB(CURDATE(),INTERVAL 55 DAY),'Fit-to-Work Assessment','Fit','Cleared after routine assessment.','EMP-1004','Information Technology','IT Support Specialist','0917-555-1004','O-','Paolo Cruz','0918-555-1004','Metro Care Clinic',DATE_ADD(CURDATE(),INTERVAL 310 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 310 DAY),'MED-1004' UNION ALL
 SELECT 'Elena Santos',DATE_SUB(CURDATE(),INTERVAL 70 DAY),'Annual Medical Checkup','Pending','Follow-up laboratory result required.','EMP-1005','Legal & Compliance','Compliance Assistant','0917-555-1005','AB+','Maria Santos','0918-555-1005','Metro Care Clinic',DATE_ADD(CURDATE(),INTERVAL 20 DAY),'Pending medical clearance.',DATE_ADD(CURDATE(),INTERVAL 20 DAY),'MED-1005' UNION ALL
 SELECT 'Felix Navarro',DATE_SUB(CURDATE(),INTERVAL 82 DAY),'Pre-Deployment Medical','Fit','Cleared for deployment processing.','EMP-1006','Deployment','Deployment Officer','0917-555-1006','A-','Rosa Navarro','0918-555-1006','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 283 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 283 DAY),'MED-1006' UNION ALL
 SELECT 'Grace Flores',DATE_SUB(CURDATE(),INTERVAL 95 DAY),'Annual Medical Checkup','Fit','Routine assessment completed.','EMP-1007','Administration','Administrative Assistant','0917-555-1007','O+','Lorna Flores','0918-555-1007','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 260 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 260 DAY),'MED-1007' UNION ALL
 SELECT 'Henry Bautista',DATE_SUB(CURDATE(),INTERVAL 110 DAY),'Fit-to-Work Assessment','Fit','Cleared for normal work.','EMP-1008','Finance','Finance Assistant','0917-555-1008','B-','Jose Bautista','0918-555-1008','Metro Care Clinic',DATE_ADD(CURDATE(),INTERVAL 255 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 255 DAY),'MED-1008' UNION ALL
 SELECT 'Irene Aquino',DATE_SUB(CURDATE(),INTERVAL 125 DAY),'Annual Medical Checkup','Fit','No restrictions recorded.','EMP-1009','Recruitment','Recruitment Assistant','0917-555-1009','A+','Teresa Aquino','0918-555-1009','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 240 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 240 DAY),'MED-1009' UNION ALL
 SELECT 'Joel Ramos',DATE_SUB(CURDATE(),INTERVAL 140 DAY),'Pre-Employment Examination','Fit','Cleared for onboarding.','EMP-1010','Operations','Records Clerk','0917-555-1010','O+','Mario Ramos','0918-555-1010','CT4 Partner Clinic',DATE_ADD(CURDATE(),INTERVAL 225 DAY),'None',DATE_ADD(CURDATE(),INTERVAL 225 DAY),'MED-1010'
) AS demo
WHERE NOT EXISTS (SELECT 1 FROM health_records h WHERE h.employee_id=demo.employee_id);

-- 10 fictional Safety Incident Reports.
INSERT INTO safety_incidents
(title,employee_name,incident_date,severity,status,description,incident_type,location,reported_by,witnesses,injury_type,treatment,immediate_action,root_cause,corrective_action,investigation_date,closed_date,days_lost,external_report_ref)
SELECT * FROM (
 SELECT 'Wet floor near reception','Ana Reyes',DATE_SUB(CURDATE(),INTERVAL 3 DAY),'Low','Closed','Slip hazard identified during routine inspection.','Near Miss','Reception Area','Safety Officer','Reception staff','No injury','No treatment required','Wet area isolated and warning sign placed.','Cleaning activity was not marked promptly.','Require wet-floor signage during cleaning.','2026-09-29', '2026-09-29',0,'INC-DEMO-001' UNION ALL
 SELECT 'Minor hand injury during filing','Ben Garcia',DATE_SUB(CURDATE(),INTERVAL 8 DAY),'Medium','Closed','Employee sustained a minor cut while handling a file box.','Minor Injury','Records Room','Operations Supervisor','Carla Mendoza','Minor cut','First aid provided.','First aid administered and task paused.','Improper handling of damaged box.','Replace damaged boxes and reinforce safe handling.','2026-09-24','2026-09-25',0,'INC-DEMO-002' UNION ALL
 SELECT 'Blocked emergency exit','Carla Mendoza',DATE_SUB(CURDATE(),INTERVAL 11 DAY),'High','Under Investigation','Emergency exit route was partially obstructed by stored materials.','Fire & Emergency','Training Room','Safety Officer','Daniel Cruz','No injury','No treatment required','Materials removed immediately.','Temporary storage controls were not followed.','Add daily exit-route inspection to checklist.','2026-09-21',NULL,0,'INC-DEMO-003' UNION ALL
 SELECT 'Loose electrical cable','Daniel Cruz',DATE_SUB(CURDATE(),INTERVAL 16 DAY),'Medium','Closed','Loose cable found near workstation walkway.','Electrical Hazard','IT Room','IT Supervisor','Felix Navarro','No injury','No treatment required','Cable secured and area checked.','Cable management tie was missing.','Implement weekly cable inspection.','2026-09-16','2026-09-16',0,'INC-DEMO-004' UNION ALL
 SELECT 'Ergonomic discomfort report','Elena Santos',DATE_SUB(CURDATE(),INTERVAL 20 DAY),'Low','Open','Employee reported discomfort after prolonged desk work.','Ergonomic','Compliance Office','HR Officer','None','Discomfort only','Ergonomic assessment scheduled.','Workstation adjusted and breaks encouraged.','Monitor height and seating position needed adjustment.','Review workstation setup and ergonomics.','2026-09-12',NULL,0,'INC-DEMO-005' UNION ALL
 SELECT 'Small water leak in pantry','Felix Navarro',DATE_SUB(CURDATE(),INTERVAL 24 DAY),'Medium','Closed','Water leak created a temporary slip hazard.','Facility Hazard','Pantry','Administration','Grace Flores','No injury','No treatment required','Area isolated and maintenance contacted.','Worn pipe fitting.','Add plumbing inspection to facilities checklist.','2026-09-08','2026-09-08',0,'INC-DEMO-006' UNION ALL
 SELECT 'Unauthorized storage near equipment','Grace Flores',DATE_SUB(CURDATE(),INTERVAL 29 DAY),'Low','Closed','Boxes were stored too close to office equipment.','Housekeeping','Supply Room','Administration','Irene Aquino','No injury','No treatment required','Boxes relocated to designated storage.','Storage capacity was exceeded.','Review storage allocation monthly.','2026-09-03','2026-09-03',0,'INC-DEMO-007' UNION ALL
 SELECT 'Printer power strip overload','Henry Bautista',DATE_SUB(CURDATE(),INTERVAL 34 DAY),'High','Under Investigation','Multiple devices were connected to one power strip.','Electrical Hazard','Finance Office','Safety Officer','Joel Ramos','No injury','No treatment required','Devices disconnected and electrical check requested.','Insufficient outlet planning.','Provide additional approved outlets and guidance.','2026-08-29',NULL,0,'INC-DEMO-008' UNION ALL
 SELECT 'Trip hazard from open drawer','Irene Aquino',DATE_SUB(CURDATE(),INTERVAL 41 DAY),'Low','Closed','Open drawer extended into a walkway.','Near Miss','Recruitment Office','Recruitment Supervisor','Ana Reyes','No injury','No treatment required','Drawer closed and reminder issued.','Drawer left open after document retrieval.','Add housekeeping reminder to office briefing.','2026-08-22','2026-08-22',0,'INC-DEMO-009' UNION ALL
 SELECT 'Manual handling strain report','Joel Ramos',DATE_SUB(CURDATE(),INTERVAL 48 DAY),'Medium','Closed','Employee reported mild strain after moving archive boxes.','Manual Handling','Archive Room','Operations Supervisor','Ben Garcia','Muscle strain','First aid and rest period.','Task stopped and employee assessed.','Box weight exceeded recommended manual handling limit.','Use carts for archive transfers and train staff.','2026-08-15','2026-08-16',0,'INC-DEMO-010'
) AS demo
WHERE NOT EXISTS (SELECT 1 FROM safety_incidents s WHERE s.title=demo.title);

-- 10 fictional Compliance Obligations.
INSERT INTO compliance_obligations
(title,report_name,report_role,contact_no,compliance_note,reported_at,category,owner,due_date,priority,status,regulation_ref,responsible_department,finding,evidence_reference,corrective_action,review_date)
SELECT * FROM (
 SELECT 'Employee records review','Quarterly Employee Records Report','Compliance Officer','02-8555-1001','Verify completeness of employee files.',NOW(),'Records','Legal & Compliance',DATE_ADD(CURDATE(),INTERVAL 7 DAY),'High','In Progress','CT4-REC-001','Legal & Compliance','Several supporting records require verification.','EVID-REC-001','Complete missing verification fields and archive evidence.',DATE_ADD(CURDATE(),INTERVAL 14 DAY) UNION ALL
 SELECT 'Safety training register update','Safety Training Compliance Report','Safety Officer','02-8555-1002','Confirm current safety training records.',NOW(),'Safety','Health & Safety',DATE_ADD(CURDATE(),INTERVAL 12 DAY),'High','Open','CT4-SAF-002','Health & Safety','Training records need periodic validation.','EVID-SAF-002','Validate certificates and schedule overdue training.',DATE_ADD(CURDATE(),INTERVAL 19 DAY) UNION ALL
 SELECT 'Medical clearance monitoring','Health Clearance Report','Health Coordinator','02-8555-1003','Monitor employee medical clearance expiry.',NOW(),'Health','Health & Safety',DATE_ADD(CURDATE(),INTERVAL 15 DAY),'Medium','In Progress','CT4-HLT-003','Health & Safety','Upcoming clearances require follow-up.','EVID-HLT-003','Contact employees with upcoming expiry dates.',DATE_ADD(CURDATE(),INTERVAL 22 DAY) UNION ALL
 SELECT 'Client deployment document review','Deployment Compliance Report','Deployment Officer','02-8555-1004','Validate deployment documents before endorsement.',NOW(),'Deployment','Operations',DATE_ADD(CURDATE(),INTERVAL 18 DAY),'Critical','Open','CT4-DEP-004','Operations','Document checklist validation is pending.','EVID-DEP-004','Complete pre-deployment document review.',DATE_ADD(CURDATE(),INTERVAL 25 DAY) UNION ALL
 SELECT 'Data privacy acknowledgement check','Privacy Compliance Report','Data Privacy Coordinator','02-8555-1005','Confirm staff acknowledgement records.',NOW(),'Data Privacy','Administration',DATE_ADD(CURDATE(),INTERVAL 22 DAY),'High','Open','CT4-PRV-005','Administration','Acknowledgements should be reviewed for new staff.','EVID-PRV-005','Collect outstanding acknowledgements.',DATE_ADD(CURDATE(),INTERVAL 29 DAY) UNION ALL
 SELECT 'Asset accountability review','Asset Accountability Report','Asset Custodian','02-8555-1006','Reconcile issued assets with custody records.',NOW(),'Assets','Administration',DATE_ADD(CURDATE(),INTERVAL 27 DAY),'Medium','In Progress','CT4-AST-006','Administration','Issued asset records require reconciliation.','EVID-AST-006','Perform asset-to-employee reconciliation.',DATE_ADD(CURDATE(),INTERVAL 34 DAY) UNION ALL
 SELECT 'Emergency contact records review','Welfare Compliance Report','HR Officer','02-8555-1007','Check emergency contact completeness.',NOW(),'Welfare','Human Resources',DATE_ADD(CURDATE(),INTERVAL 30 DAY),'Medium','Open','CT4-WEL-007','Human Resources','Some records may need updated contacts.','EVID-WEL-007','Run employee contact verification.',DATE_ADD(CURDATE(),INTERVAL 37 DAY) UNION ALL
 SELECT 'Worksite safety inspection','Worksite Inspection Report','Safety Officer','02-8555-1008','Complete scheduled worksite safety inspection.',NOW(),'Safety','Health & Safety',DATE_ADD(CURDATE(),INTERVAL 35 DAY),'High','Open','CT4-SAF-008','Health & Safety','Inspection is scheduled and evidence is pending.','EVID-SAF-008','Complete inspection and record findings.',DATE_ADD(CURDATE(),INTERVAL 42 DAY) UNION ALL
 SELECT 'Recruitment document checklist review','Recruitment Compliance Report','Recruitment Officer','02-8555-1009','Review mandatory recruitment documents.',NOW(),'Recruitment','Recruitment',DATE_ADD(CURDATE(),INTERVAL 40 DAY),'High','Open','CT4-REC-009','Recruitment','Checklist review is pending for active applicants.','EVID-REC-009','Validate required documents before endorsement.',DATE_ADD(CURDATE(),INTERVAL 47 DAY) UNION ALL
 SELECT 'Annual internal compliance review','Internal Compliance Report','Internal Auditor','02-8555-1010','Perform annual review of core compliance controls.',NOW(),'Audit','Internal Audit',DATE_ADD(CURDATE(),INTERVAL 45 DAY),'Critical','Scheduled','CT4-AUD-010','Internal Audit','Annual review is scheduled.','EVID-AUD-010','Complete evidence collection and document findings.',DATE_ADD(CURDATE(),INTERVAL 52 DAY)
) AS demo
WHERE NOT EXISTS (SELECT 1 FROM compliance_obligations c WHERE c.title=demo.title);

-- 10 fictional Compliance Audits.
INSERT INTO compliance_audits(title,audit_date,auditor,status,findings)
SELECT * FROM (
 SELECT 'Recruitment file completeness audit',DATE_ADD(CURDATE(),INTERVAL 3 DAY),'Internal Audit','Scheduled','Review recruitment files for required supporting documents.' UNION ALL
 SELECT 'Health records documentation audit',DATE_ADD(CURDATE(),INTERVAL 6 DAY),'Health & Safety Audit Team','Scheduled','Validate health record completeness and expiry tracking.' UNION ALL
 SELECT 'Safety incident closure audit',DATE_ADD(CURDATE(),INTERVAL 9 DAY),'Safety Officer','Scheduled','Review investigation and corrective-action closure.' UNION ALL
 SELECT 'Asset custody audit',DATE_ADD(CURDATE(),INTERVAL 12 DAY),'Asset Custodian','Scheduled','Reconcile issued assets and return status.' UNION ALL
 SELECT 'Data privacy control audit',DATE_ADD(CURDATE(),INTERVAL 15 DAY),'Compliance Officer','Scheduled','Review privacy acknowledgements and access controls.' UNION ALL
 SELECT 'Deployment documentation audit',DATE_ADD(CURDATE(),INTERVAL 18 DAY),'Deployment Compliance Team','Scheduled','Check deployment readiness documentation.' UNION ALL
 SELECT 'Emergency preparedness audit',DATE_ADD(CURDATE(),INTERVAL 21 DAY),'Safety Officer','Scheduled','Review emergency routes, contacts and response records.' UNION ALL
 SELECT 'Training certificate audit',DATE_ADD(CURDATE(),INTERVAL 24 DAY),'HR Compliance Team','Scheduled','Validate training certificate records and expiry dates.' UNION ALL
 SELECT 'Workplace housekeeping audit',DATE_ADD(CURDATE(),INTERVAL 27 DAY),'Facilities Officer','Scheduled','Review housekeeping and hazard-control records.' UNION ALL
 SELECT 'Annual CT4 compliance audit',DATE_ADD(CURDATE(),INTERVAL 30 DAY),'Internal Audit','Scheduled','Annual cross-module compliance review.'
) AS demo
WHERE NOT EXISTS (SELECT 1 FROM compliance_audits a WHERE a.title=demo.title);

-- 10 fictional Assets.
INSERT INTO assets(asset_tag,name,category,serial_number,quantity,status,location)
SELECT * FROM (
 SELECT 'AST-0010','Lenovo Business Laptop','Computer','SN-CT4-0010',1,'Available','Head Office' UNION ALL
 SELECT 'AST-0011','HP Business Laptop','Computer','SN-CT4-0011',1,'Issued','Recruitment Office' UNION ALL
 SELECT 'AST-0012','Dell Desktop Workstation','Computer','SN-CT4-0012',1,'Available','Finance Office' UNION ALL
 SELECT 'AST-0013','24-inch Monitor','Computer Peripheral','SN-CT4-0013',2,'Available','IT Room' UNION ALL
 SELECT 'AST-0014','Laser Printer','Printer','SN-CT4-0014',1,'Maintenance','Admin Office' UNION ALL
 SELECT 'AST-0015','Android Company Tablet','Mobile Device','SN-CT4-0015',2,'Issued','Operations Office' UNION ALL
 SELECT 'AST-0016','Portable Projector','Presentation','SN-CT4-0016',1,'Available','Training Room' UNION ALL
 SELECT 'AST-0017','Network Switch','Networking','SN-CT4-0017',1,'Available','IT Room' UNION ALL
 SELECT 'AST-0018','Barcode Scanner','Office Equipment','SN-CT4-0018',2,'Available','Records Room' UNION ALL
 SELECT 'AST-0019','UPS Backup Unit','Power Equipment','SN-CT4-0019',2,'Maintenance','IT Room'
) AS demo
WHERE NOT EXISTS (SELECT 1 FROM assets a WHERE a.asset_tag=demo.asset_tag);

-- 10 fictional Asset Issuance History rows, linked by stable asset tags.
INSERT INTO asset_issuances(asset_id,employee_name,issued_date,expected_return,return_date,status,notes)
SELECT a.id, x.employee_name, x.issued_date, x.expected_return, x.return_date, x.status, x.notes
FROM (
 SELECT 'AST-0010' asset_tag,'Ana Reyes' employee_name,DATE_SUB(CURDATE(),INTERVAL 60 DAY) issued_date,DATE_SUB(CURDATE(),INTERVAL 30 DAY) expected_return,DATE_SUB(CURDATE(),INTERVAL 32 DAY) return_date,'Returned' status,'Demo returned laptop issuance.' notes UNION ALL
 SELECT 'AST-0011','Ben Garcia',DATE_SUB(CURDATE(),INTERVAL 18 DAY),DATE_ADD(CURDATE(),INTERVAL 12 DAY),NULL,'Issued','Demo active laptop issuance.' UNION ALL
 SELECT 'AST-0012','Carla Mendoza',DATE_SUB(CURDATE(),INTERVAL 90 DAY),DATE_SUB(CURDATE(),INTERVAL 60 DAY),DATE_SUB(CURDATE(),INTERVAL 58 DAY),'Returned','Demo workstation return.' UNION ALL
 SELECT 'AST-0013','Daniel Cruz',DATE_SUB(CURDATE(),INTERVAL 15 DAY),DATE_ADD(CURDATE(),INTERVAL 15 DAY),NULL,'Issued','Demo monitor issuance.' UNION ALL
 SELECT 'AST-0014','Elena Santos',DATE_SUB(CURDATE(),INTERVAL 75 DAY),DATE_SUB(CURDATE(),INTERVAL 45 DAY),NULL,'Not Returned','Demo printer custody record; asset now under maintenance.' UNION ALL
 SELECT 'AST-0015','Felix Navarro',DATE_SUB(CURDATE(),INTERVAL 20 DAY),DATE_ADD(CURDATE(),INTERVAL 10 DAY),NULL,'Issued','Demo tablet issuance.' UNION ALL
 SELECT 'AST-0016','Grace Flores',DATE_SUB(CURDATE(),INTERVAL 50 DAY),DATE_SUB(CURDATE(),INTERVAL 20 DAY),DATE_SUB(CURDATE(),INTERVAL 19 DAY),'Returned','Demo projector return.' UNION ALL
 SELECT 'AST-0017','Henry Bautista',DATE_SUB(CURDATE(),INTERVAL 12 DAY),DATE_ADD(CURDATE(),INTERVAL 18 DAY),NULL,'Issued','Demo networking equipment custody record.' UNION ALL
 SELECT 'AST-0018','Irene Aquino',DATE_SUB(CURDATE(),INTERVAL 40 DAY),DATE_SUB(CURDATE(),INTERVAL 10 DAY),NULL,'Overdue','Demo overdue scanner issuance.' UNION ALL
 SELECT 'AST-0019','Joel Ramos',DATE_SUB(CURDATE(),INTERVAL 80 DAY),DATE_SUB(CURDATE(),INTERVAL 50 DAY),NULL,'Not Returned','Demo UPS custody record.'
) x
JOIN assets a ON a.asset_tag=x.asset_tag
WHERE NOT EXISTS (
 SELECT 1 FROM asset_issuances i WHERE i.asset_id=a.id AND i.employee_name=x.employee_name AND i.issued_date=x.issued_date
);

-- Keep asset status consistent with the issuance history.
UPDATE assets a
JOIN (
 SELECT asset_id, MAX(id) latest_id FROM asset_issuances GROUP BY asset_id
) latest ON latest.asset_id=a.id
JOIN asset_issuances i ON i.id=latest.latest_id
SET a.status=CASE
 WHEN i.status IN ('Issued','Overdue','Not Returned') THEN 'Issued'
 ELSE a.status
END
WHERE a.asset_tag IN ('AST-0011','AST-0015','AST-0018','AST-0019');

-- 10 fictional Maintenance records.
INSERT INTO maintenance_records(asset_id,service_date,service_type,cost,status,notes)
SELECT a.id,x.service_date,x.service_type,x.cost,x.status,x.notes
FROM (
 SELECT 'AST-0010' asset_tag,DATE_SUB(CURDATE(),INTERVAL 100 DAY) service_date,'Preventive Inspection' service_type,450.00 cost,'Completed' status,'Routine laptop inspection completed.' notes UNION ALL
 SELECT 'AST-0011',DATE_SUB(CURDATE(),INTERVAL 70 DAY),'Software Maintenance',600.00,'Completed','Security and operating system updates completed.' UNION ALL
 SELECT 'AST-0012',DATE_SUB(CURDATE(),INTERVAL 45 DAY),'Hardware Inspection',350.00,'Completed','Desktop hardware checked.' UNION ALL
 SELECT 'AST-0013',DATE_SUB(CURDATE(),INTERVAL 35 DAY),'Monitor Inspection',200.00,'Completed','Monitor cables and display tested.' UNION ALL
 SELECT 'AST-0014',DATE_SUB(CURDATE(),INTERVAL 5 DAY),'Printer Repair',1850.00,'In Progress','Paper-feed issue under service.' UNION ALL
 SELECT 'AST-0015',DATE_SUB(CURDATE(),INTERVAL 25 DAY),'Device Inspection',500.00,'Completed','Tablet battery and software checked.' UNION ALL
 SELECT 'AST-0016',DATE_SUB(CURDATE(),INTERVAL 80 DAY),'Projector Cleaning',750.00,'Completed','Lens and filter cleaned.' UNION ALL
 SELECT 'AST-0017',DATE_SUB(CURDATE(),INTERVAL 55 DAY),'Network Inspection',900.00,'Completed','Switch ports and power checked.' UNION ALL
 SELECT 'AST-0018',DATE_SUB(CURDATE(),INTERVAL 30 DAY),'Scanner Calibration',400.00,'Completed','Scanner calibrated and tested.' UNION ALL
 SELECT 'AST-0019',DATE_SUB(CURDATE(),INTERVAL 4 DAY),'UPS Battery Service',2200.00,'In Progress','Battery replacement assessment underway.'
) x
JOIN assets a ON a.asset_tag=x.asset_tag
WHERE NOT EXISTS (
 SELECT 1 FROM maintenance_records m WHERE m.asset_id=a.id AND m.service_date=x.service_date AND m.service_type=x.service_type
);

-- Health follow-ups for the 10 demo employees.
INSERT INTO health_followups(health_record_id,employee_name,followup_type,due_date,status,notes)
SELECT h.id,h.employee_name,x.followup_type,x.due_date,x.status,x.notes
FROM (
 SELECT 'EMP-1001' employee_id,'Annual clearance review' followup_type,DATE_ADD(CURDATE(),INTERVAL 30 DAY) due_date,'Pending' status,'Routine annual follow-up.' notes UNION ALL
 SELECT 'EMP-1002','Medical document verification',DATE_ADD(CURDATE(),INTERVAL 14 DAY),'Completed','Supporting document verified.' UNION ALL
 SELECT 'EMP-1003','Restriction review',DATE_ADD(CURDATE(),INTERVAL 20 DAY),'Pending','Review lifting restriction.' UNION ALL
 SELECT 'EMP-1004','Annual medical reminder',DATE_ADD(CURDATE(),INTERVAL 60 DAY),'Pending','Schedule next routine checkup.' UNION ALL
 SELECT 'EMP-1005','Laboratory result follow-up',DATE_ADD(CURDATE(),INTERVAL 5 DAY),'Pending','Pending laboratory result.' UNION ALL
 SELECT 'EMP-1006','Deployment clearance review',DATE_ADD(CURDATE(),INTERVAL 45 DAY),'Pending','Confirm deployment medical validity.' UNION ALL
 SELECT 'EMP-1007','Annual clearance review',DATE_ADD(CURDATE(),INTERVAL 50 DAY),'Pending','Routine follow-up.' UNION ALL
 SELECT 'EMP-1008','Medical record verification',DATE_ADD(CURDATE(),INTERVAL 40 DAY),'Completed','Record verified.' UNION ALL
 SELECT 'EMP-1009','Annual clearance review',DATE_ADD(CURDATE(),INTERVAL 55 DAY),'Pending','Routine follow-up.' UNION ALL
 SELECT 'EMP-1010','Onboarding medical follow-up',DATE_ADD(CURDATE(),INTERVAL 25 DAY),'Pending','Confirm onboarding medical documents.'
) x
JOIN health_records h ON h.employee_id=x.employee_id
WHERE NOT EXISTS (
 SELECT 1 FROM health_followups f WHERE f.health_record_id=h.id AND f.followup_type=x.followup_type
);

-- Fill blank notes for existing assets/issuances/maintenance where applicable.
UPDATE assets SET
 category=COALESCE(NULLIF(category,''),'General Equipment'),
 location=COALESCE(NULLIF(location,''),'Head Office'),
 serial_number=COALESCE(NULLIF(serial_number,''),CONCAT('SN-CT4-',LPAD(id,4,'0')))
WHERE 1=1;

UPDATE asset_issuances SET
 notes=COALESCE(NULLIF(notes,''),'Issuance record maintained for asset accountability.')
WHERE 1=1;

UPDATE maintenance_records SET
 service_type=COALESCE(NULLIF(service_type,''),'Routine Maintenance'),
 status=COALESCE(NULLIF(status,''),'Completed'),
 notes=COALESCE(NULLIF(notes,''),'Maintenance record maintained for asset service history.')
WHERE 1=1;

