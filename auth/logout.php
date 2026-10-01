<?php
require_once __DIR__ . '/../includes/error_handler.php';
 require_once __DIR__.'/../includes/helpers.php'; logout_user(); redirect('/auth/login.php'); exit;
