<?php
require_once __DIR__ . '/../includes/error_handler.php';

require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

if (current_user()) redirect('/dashboard.php');

$error='';
$success='';
$pending=$_SESSION['pending_otp_user']??null;

// Validate pending OTP session timeout
if ($pending && (!isset($_SESSION['pending_otp_created']) || (time() - (int)$_SESSION['pending_otp_created']) > (OTP_EXPIRY_MINUTES * 60))) {
    unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['csrf_token']);
    $pending=null;
    $error='Your verification code has expired. Please sign in again.';
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    // CSRF validation for all POST requests
    $csrf_token = (string)($_POST['csrf_token'] ?? '');
    if (!verify_csrf_token($csrf_token)) {
        $error='Your session expired or the request is invalid. Please try again.';
        unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created']);
        $pending=null;
    } else {
        try {
            $action=$_POST['action']??'login';

            if ($action==='verify_otp' && $pending) {
                $code=trim(preg_replace('/[^0-9]/', '', $_POST['otp'] ?? ''));

                if (!preg_match('/^\d{6}$/', $code)) {
                    $error='Verification code must be exactly 6 digits.';
                } else {
                    $stmt=db()->prepare('SELECT id,otp_hash,expires_at,attempts FROM otp_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
                    $stmt->execute([(int)$pending['id']]);
                    $row=$stmt->fetch();

                    if (!$row) {
                        $error='Your verification code is no longer available. Please sign in again.';
                        unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);
                        $pending=null;
                    } elseif (strtotime($row['expires_at']) < time()) {
                        $error='Your verification code has expired. Please sign in again.';
                        db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
                        unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);
                        $pending=null;
                    } elseif ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
                        $error='Too many incorrect attempts. Please sign in again.';
                        db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
                        unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);
                        $pending=null;
                    } elseif (!password_verify($code,$row['otp_hash'])) {
                        db()->prepare('UPDATE otp_requests SET attempts=attempts+1 WHERE id=?')->execute([(int)$row['id']]);
                        $error='Incorrect verification code.';
                    } else {
                        login_user($pending);
                        db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                        unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created'],$_SESSION['csrf_token']);

                        $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                        $history->execute([
                            (int)$pending['id'],$pending['email'],'Success',
                            $_SERVER['REMOTE_ADDR']??'Unknown',
                            substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                        ]);
                        audit('System Administration & Security','Two-Step Login','Successful password and OTP verification');
                        redirect('/dashboard.php');
                    }
                }
            } elseif ($action==='resend_otp' && $pending) {
                // Rate limiting: check if user is resending too frequently
                $last_resend = (int)($_SESSION['last_otp_resend'] ?? 0);
                if (time() - $last_resend < 30) {
                    $error='Please wait 30 seconds before requesting a new code.';
                } else {
                    $otp=(string)random_int(100000,999999);
                    $hash=password_hash($otp,PASSWORD_DEFAULT);
                    $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));
                    
                    db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                    db()->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                        ->execute([(int)$pending['id'],$hash,$expires]);
                    
                    $_SESSION['pending_otp_created'] = time();
                    $_SESSION['last_otp_resend'] = time();
                    
                    try {
                        send_otp_email($pending['email'],$pending['name'],$otp);
                        $success='A new 6-digit verification code has been sent to your email.';
                    } catch(Throwable $mailError) {
                        db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                        $error='Unable to send verification code. Check the Gmail SMTP/App Password settings and try again.';
                        error_log('OTP Email Error (Resend): ' . $mailError->getMessage());
                    }
                }
            } elseif ($action==='login') {
                $email=strtolower(trim($_POST['email']??''));
                $password=$_POST['password']??'';

                // Input validation
                if (empty($email) || empty($password)) {
                    $error='Email and password are required.';
                } else {
                    $stmt=db()->prepare('SELECT id,name,email,role,password_hash FROM users WHERE LOWER(email)=LOWER(?) AND active=1 LIMIT 1');
                    $stmt->execute([$email]);
                    $u=$stmt->fetch();

                    if ($u && password_verify($password,$u['password_hash'])) {
                        db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$u['id']]);

                        $otp=(string)random_int(100000,999999);
                        $otpHash=password_hash($otp,PASSWORD_DEFAULT);
                        $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));

                        db()->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                            ->execute([(int)$u['id'],$otpHash,$expires]);

                        try {
                            send_otp_email($u['email'],$u['name'],$otp);
                            $_SESSION['pending_otp_user']=[
                                'id'=>(int)$u['id'],'name'=>$u['name'],
                                'email'=>$u['email'],'role'=>$u['role']
                            ];
                            $_SESSION['pending_otp_created']=time();
                            $_SESSION['last_otp_resend']=time();
                            $pending=$_SESSION['pending_otp_user'];
                            $success='Verification code sent. Enter the 6-digit OTP below to continue to the dashboard.';

                            $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                            $history->execute([
                                (int)$u['id'],$email,'OTP Pending',
                                $_SERVER['REMOTE_ADDR']??'Unknown',
                                substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                            ]);
                        } catch(Throwable $mailError) {
                            db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$u['id']]);
                            $error='We could not send the verification code. Check the Gmail SMTP/App Password settings and try again.';
                            error_log('OTP Email Error (Login): ' . $mailError->getMessage());
                        }
                    } else {
                        // Generic error to prevent username enumeration
                        $error='Invalid email or password.';
                        $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                        $history->execute([
                            $u ? (int)$u['id'] : null,$email,'Failed',
                            $_SERVER['REMOTE_ADDR']??'Unknown',
                            substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                        ]);
                    }
                }
            }
        } catch(Throwable $e) {
            $error='Unable to process the request. Check the database and Gmail settings.';
            error_log('OTP Login Critical Error: ' . $e->getMessage() . ' | ' . $e->getFile() . ':' . $e->getLine());
        }
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= $pending ? 'Enter OTP' : 'Sign In' ?> — Great Solomon Manpower Services Inc.</title>
<link rel="stylesheet" href="../style.css">

</head>
<body class="auth-body">
<div class="login-page">
  <div class="login-card auth-card <?= $pending ? 'otp-card' : '' ?>">
    <div class="brand-mark auth-brand">
      <div class="brand-logo-white auth-logo-wrap"><img src="../assets/logo2.svg" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
      <div class="auth-brand-copy"><strong>Great Solomon Manpower Services Inc.</strong><div class="small-muted">Core Transaction 4</div></div>
    </div>

    <?php if($pending): ?>
      <div class="auth-heading">
        <span class="material-symbols-outlined">shield_lock</span>
        <h1>Enter OTP</h1>
        <p>Login successful. Enter the <strong>6-digit OTP</strong> sent to <strong><?=e($pending['email'])?></strong> to continue to the dashboard.</p>
      </div>
      <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>
      <?php if($success):?><div class="notice success auth-success"><?=e($success)?></div><?php endif;?>

      <form method="post" class="auth-form">
        <input type="hidden" name="action" value="verify_otp">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="field otp-field">
          <label for="otp">One-Time Password</label>
          <input id="otp" class="otp-input" type="text" name="otp" inputmode="numeric"
                 pattern="\d{6}" maxlength="6" autocomplete="one-time-code"
                 placeholder="000000" required autofocus>
        </div>
        <button class="gw-btn primary auth-submit" type="submit">
          <span class="material-symbols-outlined">verified</span> Verify &amp; Open Dashboard
        </button>
      </form>

      <form method="post" class="resend-form">
        <input type="hidden" name="action" value="resend_otp">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <button type="submit" class="auth-link">Resend verification code</button>
      </form>
      <a class="auth-link secondary" href="../auth/logout.php">Use a different account</a>
      <div class="auth-security-note">
        <span class="material-symbols-outlined">schedule</span>
        The code expires in <?=OTP_EXPIRY_MINUTES?> minutes
      </div>

    <?php else: ?>
      <div class="auth-heading">
        <span class="material-symbols-outlined">lock</span>
        <h1>Welcome Back</h1>
        <p>Sign in to access Governance, Safety &amp; System Administration.</p>
      </div>
      <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>
      <?php if($success):?><div class="notice success auth-success"><?=e($success)?></div><?php endif;?>

      <form method="post" class="auth-form">
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="csrf_token" value="<?=e(csrf_token())?>">
        <div class="field"><label>Email Address</label><input type="email" name="email" autocomplete="username" required value="<?=e($_POST['email']??'')?>"></div>
        <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>
        <button class="gw-btn primary auth-submit" type="submit">
          <span class="material-symbols-outlined">login</span> Sign In
        </button>
      </form>
      <div class="auth-security-note">
        <span class="material-symbols-outlined">verified_user</span>
        After login, a 6-digit OTP is required before the dashboard opens.
      </div>
    <?php endif; ?>
  </div>
</div>
<script src="../app.js"></script>
</body>
</html>
