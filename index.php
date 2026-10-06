<?php
require_once __DIR__.'/includes/helpers.php';
if (current_user()) {
    redirect('/dashboard.php');
}
redirect('/auth/login.php');
