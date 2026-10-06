<?php
// Compatibility endpoint: OTP verification is handled by auth/login.php.
// Keeping a single verification flow prevents security fixes from diverging.
require_once __DIR__.'/../includes/helpers.php';
if (current_user()) {
    redirect('/dashboard.php');
}
redirect('/auth/login.php');
