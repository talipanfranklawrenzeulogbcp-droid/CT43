<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';

if (current_user()) redirect('/dashboard.php');

$error='';
$success='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $action=(string)($_POST['action']??'login');
        if ($action!=='login') throw new RuntimeException('Invalid sign-in request.');

        $email=strtolower(trim((string)($_POST['email']??'')));
        $password=(string)($_POST['password']??'');
        $terms=(string)($_POST['terms_accepted']??'0');

        if ($terms!=='1') throw new RuntimeException('Please confirm the Terms and Conditions before signing in.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $password==='') throw new RuntimeException('Please enter a valid email address and password.');

        $stmt=db()->prepare('SELECT id,name,email,password_hash,role,active,face_id_credential_id FROM users WHERE email=? LIMIT 1');
        $stmt->execute([$email]);
        $u=$stmt->fetch();

        if (!$u || !(int)$u['active'] || !password_verify($password,(string)$u['password_hash'])) {
            $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
            $history->execute([$u?(int)$u['id']:null,$email,'Failed',$_SERVER['REMOTE_ADDR']??'Unknown',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
            throw new RuntimeException('Invalid email or password.');
        }

        $isPrimaryAdmin = strtolower((string)$u['email']) === 'adminct4@gmail.com' && (string)$u['role'] === 'Administrator';

        if ($isPrimaryAdmin) {
            login_user($u);
            $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
            $history->execute([(int)$u['id'],$u['email'],'Success',$_SERVER['REMOTE_ADDR']??'Unknown',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
            audit('System Administration & Security','Administrator Login','Primary administrator password login bypassed Face ID verification');
            redirect('/dashboard.php');
        }

        $designated=(string)($u['face_id_credential_id']??'');
        if ($designated==='') {
            $cred=db()->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=? ORDER BY id');
            $cred->execute([(int)$u['id']]);
            $credentials=$cred->fetchAll(PDO::FETCH_COLUMN);

            if (count($credentials)===1) {
                $designated=(string)$credentials[0];
                db()->prepare('UPDATE users SET face_id_credential_id=? WHERE id=? AND (face_id_credential_id IS NULL OR face_id_credential_id=\'\')')
                    ->execute([$designated,(int)$u['id']]);
            }
        }

        if ($designated==='') {
            throw new RuntimeException('This account has no registered Face ID. Ask an administrator to register the account Face ID before signing in.');
        }

        $check=db()->prepare('SELECT id FROM webauthn_credentials WHERE user_id=? AND credential_id=? LIMIT 1');
        $check->execute([(int)$u['id'],$designated]);
        if(!$check->fetchColumn()) {
            throw new RuntimeException('The registered Face ID is no longer available. Ask an administrator to register a new Face ID before signing in.');
        }

        $_SESSION['pending_biometric_user']=[
            'id'=>(int)$u['id'],'name'=>(string)$u['name'],'email'=>(string)$u['email'],'role'=>(string)$u['role']
        ];
        $_SESSION['pending_face_id_credential']=$designated;
        $_SESSION['pending_biometric_created']=time();
        unset($_SESSION['biometric_enroll_existing']);
        redirect('/auth/biometric.php');
    } catch(Throwable $e) {
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Sign In — Great Solomon Manpower Services Inc.</title>
<link rel="stylesheet" href="../style.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet">
</head>
<body class="auth-body">
<div class="login-page">
  <div class="login-card auth-card">
    <div class="brand-mark auth-brand">
      <div class="brand-logo-white auth-logo-wrap"><img src="../assets/logo2.svg" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
      <div class="auth-brand-copy"><strong>Great Solomon Manpower Services Inc.</strong><div class="small-muted">Core Transaction 4</div></div>
    </div>

    <div class="auth-heading">
      <span class="material-symbols-outlined">lock</span>
      <h1>Welcome Back</h1>
      <p>Sign in to access Governance, Safety &amp; System Administration.</p>
    </div>
    <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>

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
      <span class="material-symbols-outlined">face</span>
      Password verification is followed by the Face ID already registered for this account.
    </div>
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
})();
</script>
<script src="../app.js"></script>
</body>
</html>
