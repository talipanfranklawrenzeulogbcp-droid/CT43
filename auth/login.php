<?php
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/mailer.php';

if (current_user()) redirect('/dashboard.php');

$error='';
$success='';
$pending=$_SESSION['pending_otp_user']??null;
// Keep the pending-login session alive slightly longer than one OTP lifetime.
// An expired OTP can still be resent without forcing the user through password
// authentication again, while abandoned pending sessions are eventually cleared.
$pendingCreated=(int)($_SESSION['pending_otp_created'] ?? 0);
if ($pending && (!$pendingCreated || (time() - $pendingCreated) > (OTP_PENDING_SESSION_MINUTES * 60))) {
    unset($_SESSION['pending_otp_user'], $_SESSION['pending_otp_created'], $_SESSION['otp_last_resend']);
    $pending=null;
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=$_POST['action']??'login';

        if ($action==='verify_otp' && $pending) {
            $code=preg_replace('/\D/','',$_POST['otp']??'');
            $stmt=db()->prepare('SELECT id,otp_hash,expires_at,attempts FROM otp_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
            $stmt->execute([(int)$pending['id']]);
            $row=$stmt->fetch();

            if ($row && !isset($row['otp_hash'],$row['expires_at'],$row['attempts'])) {
                $row=null;
            }

            if (!$row) {
                $error='Your verification code is no longer available. Please sign in again.';
                unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);
                $pending=null;
            } elseif (strtotime($row['expires_at']) < time()) {
                // Keep the pending-login session so the existing Resend action
                // remains usable after an OTP expires.
                $error='Your verification code has expired. Request a new code to continue.';
                db()->prepare('DELETE FROM otp_requests WHERE id=?')->execute([(int)$row['id']]);
            } elseif ((int)$row['attempts'] >= OTP_MAX_ATTEMPTS) {
                $error='Too many incorrect attempts. Please sign in again.';
            } elseif (!preg_match('/^\d{6}$/',$code) || !password_verify($code,$row['otp_hash'])) {
                db()->prepare('UPDATE otp_requests SET attempts=attempts+1 WHERE id=?')->execute([(int)$row['id']]);
                $error='Incorrect verification code.';
            } else {
                login_user($pending);
                db()->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                unset($_SESSION['pending_otp_user'],$_SESSION['pending_otp_created']);

                $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                $history->execute([
                    (int)$pending['id'],$pending['email'],'Success',
                    $_SERVER['REMOTE_ADDR']??'Unknown',
                    substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                ]);
                audit('System Administration & Security','Two-Step Login','Successful password and OTP verification');
                redirect('/dashboard.php');
            }
        } elseif ($action==='resend_otp' && $pending) {
            $lastResend=(int)($_SESSION['otp_last_resend'] ?? 0);
            $cooldown=OTP_RESEND_COOLDOWN_SECONDS;
            if($lastResend && (time()-$lastResend)<$cooldown){
                $remaining=max(1,$cooldown-(time()-$lastResend));
                throw new RuntimeException('Please wait '.$remaining.' seconds before requesting another verification code.');
            }

            $otp=(string)random_int(100000,999999);
            $hash=password_hash($otp,PASSWORD_DEFAULT);
            if ($hash === false) {
                throw new RuntimeException('Unable to prepare the verification code. Please try again.');
            }
            $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));

            // Store the new OTP before attempting delivery so verification can
            // never receive an email for a code that was not persisted. Keep a
            // copy of the previous record so a failed resend can restore it.
            $pdo=db();
            $previous=null;
            $previousStmt=$pdo->prepare('SELECT id,otp_hash,expires_at,attempts FROM otp_requests WHERE user_id=? ORDER BY id DESC LIMIT 1');
            $previousStmt->execute([(int)$pending['id']]);
            $previous=$previousStmt->fetch() ?: null;

            try {
                $pdo->beginTransaction();
                $pdo->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                $pdo->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                    ->execute([(int)$pending['id'],$hash,$expires]);
                $pdo->commit();

                try {
                    send_otp_email($pending['email'],$pending['name'],$otp);
                } catch(Throwable $mailError) {
                    // Restore the previous working OTP when delivery fails.
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$pending['id']]);
                        if ($previous) {
                            $pdo->prepare('INSERT INTO otp_requests(id,user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,?,?)')
                                ->execute([(int)$previous['id'],(int)$pending['id'],$previous['otp_hash'],$previous['expires_at'],(int)$previous['attempts']]);
                        }
                        $pdo->commit();
                    } catch(Throwable $restoreError) {
                        if ($pdo->inTransaction()) $pdo->rollBack();
                        throw $mailError;
                    }
                    throw $mailError;
                }

                // Start the cooldown only after a successful delivery and DB update.
                $_SESSION['otp_last_resend']=time();
                $_SESSION['pending_otp_created']=time();
                $pending=$_SESSION['pending_otp_user'];
                $success='A new 6-digit verification code has been sent to your email.';
            } catch(Throwable $mailError) {
                $error='Unable to send verification code. Your previous code is still available. Check the Gmail SMTP/App Password settings and try again.';
            }
        } elseif ($action==='login') {
            $email=strtolower(trim((string)($_POST['email']??'')));
            $password=(string)($_POST['password']??'');
            if (($_POST['terms_accepted'] ?? '') !== '1') {
                throw new RuntimeException('Please read the Terms and Conditions, tick the acceptance checkbox, and confirm before signing in.');
            }

            // Basic brute-force protection: five failed password attempts for the
            // same email/IP within 15 minutes requires waiting before another try.
            $ip=(string)($_SERVER['REMOTE_ADDR']??'Unknown');
            $rate=db()->prepare("SELECT COUNT(*) FROM login_history WHERE status='Failed' AND email=? AND ip_address=? AND login_at >= DATE_SUB(NOW(), INTERVAL 15 MINUTE)");
            $rate->execute([$email,$ip]);
            if((int)$rate->fetchColumn() >= 5){
                throw new RuntimeException('Too many failed sign-in attempts. Please wait 15 minutes and try again.');
            }

            $stmt=db()->prepare('SELECT id,name,email,role,password_hash FROM users WHERE LOWER(email)=? AND active=1 LIMIT 1');
            $stmt->execute([$email]);
            $u=$stmt->fetch();

            if ($u && password_verify($password,$u['password_hash'])) {
                $otp=(string)random_int(100000,999999);
                $otpHash=password_hash($otp,PASSWORD_DEFAULT);
                if($otpHash===false) throw new RuntimeException('Unable to prepare the verification code.');
                $expires=date('Y-m-d H:i:s',time()+(OTP_EXPIRY_MINUTES*60));

                try {
                    // Persist the OTP BEFORE sending it. This fixes the race where
                    // Gmail accepts the message but the verification record was
                    // never committed, producing an apparently valid code that
                    // the application cannot verify.
                    $pdo=db();
                    $pdo->beginTransaction();
                    try {
                        $pdo->prepare('DELETE FROM otp_requests WHERE user_id=?')->execute([(int)$u['id']]);
                        $pdo->prepare('INSERT INTO otp_requests(user_id,otp_hash,expires_at,attempts) VALUES(?,?,?,0)')
                            ->execute([(int)$u['id'],$otpHash,$expires]);
                        $pdo->commit();
                    } catch(Throwable $dbError) {
                        if($pdo->inTransaction()) $pdo->rollBack();
                        throw $dbError;
                    }

                    // Establish the pending-login session BEFORE sending mail. If
                    // SMTP is temporarily unavailable, the user remains on the OTP
                    // screen and can immediately use Resend instead of being forced
                    // back through password authentication.
                    $_SESSION['pending_otp_user']=[
                        'id'=>(int)$u['id'],'name'=>$u['name'],
                        'email'=>$u['email'],'role'=>$u['role']
                    ];
                    $_SESSION['pending_otp_created']=time();
                    unset($_SESSION['otp_last_resend']);
                    $pending=$_SESSION['pending_otp_user'];

                    // Only after the OTP is durable do we send it. If delivery
                    // fails, the exact stored OTP remains available for resend.
                    send_otp_email($u['email'],$u['name'],$otp);

                    $_SESSION['otp_last_resend']=time();
                    $success='Verification code sent. Enter the 6-digit OTP below to continue to the dashboard.';

                    $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                    $history->execute([
                        (int)$u['id'],$email,'OTP Pending',
                        $_SERVER['REMOTE_ADDR']??'Unknown',
                        substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                    ]);
                } catch(Throwable $mailError) {
                    // Keep the pending session and durable OTP so Resend works
                    // even when the first delivery attempt fails.
                    $pending=$_SESSION['pending_otp_user'] ?? null;
                    $error='The OTP was generated and saved, but email delivery failed. Use Resend verification code or check the SMTP/App Password configuration.';
                    otp_mail_log('Initial OTP delivery failed for user '.(int)$u['id'].': '.$mailError->getMessage());
                }
            } else {
                $error='Invalid email or password.';
                $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
                $history->execute([
                    $u ? (int)$u['id'] : null,$email,'Failed',
                    $_SERVER['REMOTE_ADDR']??'Unknown',
                    substr($_SERVER['HTTP_USER_AGENT']??'',0,500)
                ]);
            }
        }
    } catch(Throwable $e) {
        $error='Unable to process the request. Check the database and Gmail settings.';
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
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet">
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
      <?php if($success):?><div class="notice success auth-error"><?=e($success)?></div><?php endif;?>

      <form method="post" class="auth-form"><?=csrf_field()?>
        <input type="hidden" name="action" value="verify_otp">
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

      <form method="post" class="resend-form" id="resendForm"><?=csrf_field()?>
        <input type="hidden" name="action" value="resend_otp">
        <button type="submit" class="auth-link" id="resendButton" data-cooldown="<?=OTP_RESEND_COOLDOWN_SECONDS?>">Resend verification code</button>
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
      <?php if($success):?><div class="notice success auth-error"><?=e($success)?></div><?php endif;?>

      <form method="post" class="auth-form" id="loginForm"><?=csrf_field()?>
        <input type="hidden" name="action" value="login">
        <input type="hidden" name="terms_accepted" id="termsAccepted" value="0">
        <div class="field"><label>Email Address</label><input type="email" name="email" autocomplete="username" required value="<?=e($_POST['email']??'')?>"></div>
        <div class="field"><label>Password</label><input type="password" name="password" autocomplete="current-password" required></div>

        <details class="auth-terms" id="loginTerms">
          <summary><span>Terms and Conditions</span><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
          <div class="auth-terms-body">
            <p>By accessing and using this system, you acknowledge that it is intended only for authorized Great Solomon Manpower Services Inc. administrators and staff. Use your assigned account appropriately, keep authentication information confidential, and ensure records you create or update are accurate and used only for legitimate company purposes.</p>
            <p>The system may process personal and sensitive personal information. Such information must be handled only as authorized for your work responsibilities and in accordance with applicable company policies and Philippine data-protection requirements.</p>
            <p>Unauthorized access, account sharing, bypassing security controls, misuse of records, disruption, malicious code, or other prohibited activity is not permitted. System activity may be logged for legitimate security, audit, operational, and compliance purposes.</p>
            <p>Health, safety, welfare, recruitment, employment, and legal-compliance records must be used only for authorized business purposes. Applicable laws and regulations prevail where these terms conflict with a legal requirement.</p>
            <div class="auth-terms-accept">
              <label class="auth-checkbox">
                <input type="checkbox" id="termsCheckbox" required>
                <span>I have read and agree to the Terms and Conditions.</span>
              </label>
              <button type="button" class="gw-btn secondary auth-terms-confirm" id="confirmTerms" disabled>
                <span class="material-symbols-outlined">check_circle</span> Confirm Terms
              </button>
            </div>
          </div>
        </details>

        <button class="gw-btn primary auth-submit" type="submit" id="loginSubmit" disabled>
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
<script>
(function(){
  const checkbox=document.getElementById('termsCheckbox');
  const confirm=document.getElementById('confirmTerms');
  const accepted=document.getElementById('termsAccepted');
  const submit=document.getElementById('loginSubmit');
  const terms=document.getElementById('loginTerms');
  if(!checkbox||!confirm||!accepted||!submit)return;
  checkbox.addEventListener('change',()=>{confirm.disabled=!checkbox.checked;});
  confirm.addEventListener('click',()=>{
    if(!checkbox.checked)return;
    accepted.value='1';
    submit.disabled=false;
    confirm.innerHTML='<span class="material-symbols-outlined">verified</span> Terms Confirmed';
    confirm.classList.add('confirmed');
    terms.open=false;
  });
  document.getElementById('loginForm')?.addEventListener('submit',e=>{
    if(accepted.value!=='1'){e.preventDefault();terms.open=true;checkbox.focus();}
  });

  // Prevent accidental double-clicks on resend without changing the existing UI.
  const resendForm=document.getElementById('resendForm');
  const resendButton=document.getElementById('resendButton');
  if(resendForm && resendButton){
    resendForm.addEventListener('submit',()=>{
      resendButton.disabled=true;
      const original=resendButton.textContent;
      let remaining=parseInt(resendButton.dataset.cooldown||'15',10);
      const tick=()=>{
        if(remaining>0){
          resendButton.textContent=original+' ('+remaining+'s)';
          remaining-=1;
          window.setTimeout(tick,1000);
        } else {
          resendButton.textContent=original;
          resendButton.disabled=false;
        }
      };
      tick();
    });
  }
})();
</script>
<script src="../app.js"></script>
</body>
</html>
