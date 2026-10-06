<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
if (current_user()) redirect('/dashboard.php');
http_response_code(404);
header('Location: '.app_url('/auth/login.php'));
exit;
