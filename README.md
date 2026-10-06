# Great Solomon Manpower Services Inc. Core Transaction 4 — PHP/MySQL Backend

This package is a database-backed PHP application for Core Transaction 4.

## Structure
- `auth/` — login and logout
- `modules/health_safety/` — Health, Safety & Welfare
- `modules/legal_compliance/` — Legal & Compliance
- `modules/system_admin_security/` — System Administration & Security
- `dashboard.php` — Reports, Analysis & Dashboard (central tracking for the four modules)
- `modules/asset_equipment/` — Asset & Equipment Issuance
- `includes/` — database, authentication and helper code
- `database/database.sql` — MySQL database and tables
- `app.js` — JavaScript
- `style.css` — CSS


## Install
1. Put the project in Apache/XAMPP `htdocs`.
2. Import `database/database.sql` into MySQL/phpMyAdmin.
3. If needed, edit `includes/config.php` or set `GSMS_DB_HOST`, `GSMS_DB_NAME`, `GSMS_DB_USER`, and `GSMS_DB_PASS`.
4. Open the project in a browser.

### Database connection troubleshooting
For production/container deployment, configure `GSMS_DB_HOST`, `GSMS_DB_PORT`,
`GSMS_DB_NAME`, `GSMS_DB_USER`, and `GSMS_DB_PASS` in the platform's server-side
environment settings. `DATABASE_URL` and common `DB_*` / `MYSQL_*` variable names
are also accepted. The application no longer silently falls back to a local
database in production when the required deployment variables are missing.

Use `/health.php?db=1` to test the live database connection. A successful response
contains `"status":"ok"` and `"database":"ok"`. A failed database check returns
HTTP 503 without exposing the database password.

## Default login
- Name: `Admin`
- Email: `adminct4@gmail.com`
- Password: `ISMERSCT4`

## Login flow
The login uses the account password followed by a 6-digit Gmail OTP before the dashboard opens.

Passwords are stored using PHP `password_hash()` and verified with `password_verify()`.

## Notes
The application uses the shared authentication and helper files across all modules. Logging out destroys the current session.


## Two-Step Login (Gmail OTP)

The login now uses password + a 6-digit OTP before opening the dashboard.

- Sender: `governancesafety21@gmail.com`
- SMTP: Gmail on port 587 with STARTTLS
- OTP validity: 10 minutes
- Maximum OTP attempts: 5
- Resend OTP is available on the verification screen.
- Administrator seed account: `adminct4@gmail.com`
- Administrator name: `Admin`
- Administrator password: `ISMERSCT4`

For production, move the Gmail App Password from `includes/config.php` to environment variables:
`GSMS_MAIL_USERNAME` and `GSMS_MAIL_PASSWORD`.


---

## Microservices update

This version separates the four Core Transaction 4 modules into service boundaries under `services/`. See `MICROSERVICES.md` for the service map, API gateway, connection flow, and deployment notes.




## CT4 UI updates
- Health, Safety & Welfare now supports date filtering with calendar controls for Health Records and Safety Incident Reports.
- Login History displays only date/time, email, user, and role.
- User Accounts includes a centered Edit User modal for name, email, and optional password changes.
- Asset Issuance History includes Returned / Not Returned actions and a detectable status badge.
- The top-right user name opens Feedback, Terms and Conditions, and Logout. Feedback is emailed to the first active Administrator account.


## Gemini AI System Assistant
The sidebar now includes **AI System Assistant**. It uses a server-side Gemini API call and supplies Gemini with authorized CT4 context such as module descriptions, documentation, live record counts, recent audit metadata, login totals, and the current signed-in user's name/role.

For security, passwords/password hashes, OTPs, SMTP credentials, API keys, session tokens and database credentials are not sent to Gemini. The browser never receives the Gemini API key.

### Server environment
Set these variables on the PHP server (not in JavaScript or a public file):
- `GEMINI_API_KEY` — your Gemini API key
- `GEMINI_MODEL` — defaults to `gemini-3.8-flash`

See `.env.example` for the names only. For the supplied local build, the Gemini key is stored in the server-only `.env` file and is never sent to browser JavaScript. Rotate the supplied key after testing because it was shared during setup.


## CT4 Authentication and Gmail OTP
The supplied build is configured to use `governancesafety21@gmail.com` as the Gmail SMTP sender for login OTP messages. The sender uses a Gmail App Password (not the normal Gmail password). For production deployment, set `GSMS_MAIL_USERNAME`, `GSMS_MAIL_PASSWORD`, `GSMS_MAIL_FROM_EMAIL`, and `GSMS_OTP_SENDER_EMAIL` as server environment variables instead of relying on the bundled fallback.

The database seed provisions the main administrator as:
- Email: `adminct4@gmail.com`
- Role: `Administrator`

Import `database/database.sql` into MySQL before first use. PHP must have OpenSSL and cURL enabled. If SMTP is blocked by the hosting provider, allow outbound SMTP/TLS traffic on port 587.

## Role-based access
Staff accounts can access Reports, Analysis & Dashboard, AI System Assistant, Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance. System Administration & Security is administrator-only. Administrator dashboards include staff activity tracking for the Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance modules through audit records.


## Production readiness and security
- All state-changing requests use a server-side CSRF token.
- Login failures are rate-limited per email/IP, and OTP resend requests are throttled.
- Sessions use strict mode, HttpOnly cookies, SameSite=Lax, and HTTPS-aware secure cookies.
- Administrator-only APIs and irreversible archive deletion are protected server-side, not only by the UI.
- Uploaded files are limited to 10 MB; potentially executable web formats are forced to download.
- `.env`, SQL dumps, logs, and internal documentation are blocked from direct Apache access.
- Use `.env.example` as the deployment variable template. Do not commit real SMTP passwords, Gemini keys, or database credentials.
- Change the seeded administrator password immediately after first installation.
- Recommended readiness probe: `/health.php?db=1` when the deployment platform supports database-aware health checks.


## Enhanced Health, Safety & Compliance workflow
The Health, Safety & Welfare module now preserves existing records while supporting:
- employee ID, department, position, contact, emergency contact, medical provider, blood type, next checkup, restrictions, clearance expiry and document references;
- safety incident type/location/reporting/witness/injury/treatment/immediate action/root cause/corrective action/investigation/closure/days lost/external reference;
- incident status and severity updates plus corrective/preventive/investigation action tracking;
- health follow-up scheduling and completion tracking.

The Legal & Compliance module now preserves the existing compliance report and obligation records while supporting:
- regulation/permit references, responsible department, findings, evidence references, corrective actions and review dates;
- compliance status/priority updates;
- corrective/preventive action items with owners, due dates and completion status;
- audit scheduling and findings;
- dashboard tracking for overdue health follow-ups and due compliance actions.

All enhancements are additive database migrations. Existing data is not intentionally replaced or deleted by the upgrade.
## Employee Legal & Compliance Documents
The Legal & Compliance module includes an employee document register sourced from Health, Safety & Welfare records. It supports secure database-backed upload/view, document verification status, expiry monitoring, archiving/recovery, audit logging, and a recruitment-agency checklist covering identity, recruitment, employment, qualifications, health/welfare, safety/training, compliance, deployment/client, and benefits records. Existing database records and module workflows are preserved.



## Demo data
The database seed includes an idempotent CT4 demo-data pack. It adds 10 fictional health records, 10 safety incidents, 10 compliance obligations, 10 compliance audits, 10 assets, 10 issuance-history records, 10 maintenance records, and health follow-ups. It also fills blank enhanced fields on existing health, safety, compliance, asset, issuance, and maintenance records without overwriting populated values. Re-importing the seed does not duplicate the demo records.


## Feedback Functionality
The feedback module uses the existing `feedback_threads` and `feedback_messages` tables.
Administrators can reply to employee feedback and delete an entire feedback conversation from
the feedback inbox. Replies create a conversation message, notify the employee, and update the
thread status to `Replied`. Deletion removes the thread, cascades its messages, and removes
notifications associated with that thread. No database schema changes are required.
