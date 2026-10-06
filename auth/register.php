<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
http_response_code(403);
if (current_user() && (current_user()['role'] ?? '') === 'Administrator') {
    redirect('/modules/system_admin_security/index.php#create-user');
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Account Creation Restricted — Great Solomon Manpower Services Inc.</title><link rel="stylesheet" href="../style.css"></head><body class="auth-body"><div class="login-page"><div class="login-card auth-card"><div class="auth-heading"><span class="material-symbols-outlined">admin_panel_settings</span><h1>Account Creation Restricted</h1><p>For security, only an administrator can create accounts. Please contact your system administrator.</p><a class="gw-btn primary" href="../auth/login.php">Return to Sign In</a></div></div></div></body></html>
