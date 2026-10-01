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


## HTTP 500 / server requirements
The application requires **PHP 8.0+** with the `pdo_mysql`, `openssl`, and `curl` extensions enabled, plus MySQL/MariaDB. If the server reports HTTP 500, open `health.php` first: it now reports whether PHP, PDO MySQL, and the database connection are available. Verify the `GSMS_DB_*` environment variables or the values in `includes/config.php`, then restart PHP/Apache after enabling extensions.

## Install
1. Put the project in Apache/XAMPP `htdocs`.
2. Import `database/database.sql` into MySQL/phpMyAdmin.
3. If needed, edit `includes/config.php` or set `GSMS_DB_HOST`, `GSMS_DB_NAME`, `GSMS_DB_USER`, and `GSMS_DB_PASS`.
4. Open the project in a browser.

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

For production, set the Gmail App Password through the server environment variables `GSMS_MAIL_USERNAME` and `GSMS_MAIL_PASSWORD`.


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

See `.env.example` for the names only. The package does not include a live `.env` file or API key. Copy `.env.example` to `.env` for local development, fill in your own secrets, and never commit `.env`.


## CT4 Authentication and Gmail OTP
The supplied build is configured to use `governancesafety21@gmail.com` as the Gmail SMTP sender for login OTP messages. The sender uses a Gmail App Password (not the normal Gmail password). For production deployment, set `GSMS_MAIL_USERNAME`, `GSMS_MAIL_PASSWORD`, `GSMS_MAIL_FROM_EMAIL`, and `GSMS_OTP_SENDER_EMAIL` as server environment variables instead of relying on the bundled fallback.

The database seed provisions the main administrator as:
- Email: `adminct4@gmail.com`
- Role: `Administrator`

Import `database/database.sql` into MySQL before first use. For an existing CT4 database, run `database/migrate_existing.sql` once before deploying this version; runtime page requests no longer execute schema `ALTER TABLE` operations. PHP must have OpenSSL and cURL enabled. If SMTP is blocked by the hosting provider, allow outbound SMTP/TLS traffic on port 587.

## Role-based access
Staff accounts can access Reports, Analysis & Dashboard, AI System Assistant, Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance. System Administration & Security is administrator-only. Administrator dashboards include staff activity tracking for the Health, Safety & Welfare, Legal & Compliance, and Asset & Equipment Issuance modules through audit records.


## HTTP 500 diagnostics and logging

- Uncaught exceptions, PHP warnings/notices, and fatal shutdown errors are logged to `storage/logs/php-error.log` (the directory is created automatically when permissions allow).
- Visitors receive the generic `500.php` error page with a reference ID; technical details are not shown publicly.
- Apache is configured with `ErrorDocument 500 /500.php`. The app-level handler also renders the page for uncaught PHP exceptions and fatal errors when headers have not already been sent.
- Ensure the PHP/Apache user can write to `storage/` (recommended directory permissions: `0750`, adjusted to your hosting user/group). Keep `storage/` inaccessible from the web; the included `.htaccess` denies it.
- Review `storage/logs/php-error.log` on the server to diagnose the exact failure. Do not expose this log publicly or share it without removing credentials, tokens, and personal data.
- `health.php` is an operational diagnostic endpoint and may reveal dependency status; restrict it to administrators or disable it on public production deployments if that information is sensitive.
