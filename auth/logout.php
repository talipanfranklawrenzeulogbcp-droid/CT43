<?php
require_once __DIR__.'/../includes/auth.php';

logout_user();

// Always return to the application root login route, including when the app
// is deployed under a subdirectory or behind a reverse proxy.
header('Location: '.app_url('/auth/login.php'));
exit;
