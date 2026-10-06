<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';

if (current_user()) redirect('/dashboard.php');

$error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    verify_csrf();
    try {
        $name=trim((string)($_POST['name']??''));
        $email=strtolower(trim((string)($_POST['email']??'')));
        $password=(string)($_POST['password']??'');
        $confirm=(string)($_POST['confirm_password']??'');
        $terms=(string)($_POST['terms_accepted']??'0');

        if ($terms!=='1') throw new RuntimeException('Please confirm the Terms and Conditions.');
        if (mb_strlen($name)<2 || mb_strlen($name)>120) throw new RuntimeException('Please enter your full name.');
        if (!filter_var($email,FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Please enter a valid email address.');
        if (strlen($password)<8) throw new RuntimeException('Password must be at least 8 characters.');
        if (!hash_equals($password,$confirm)) throw new RuntimeException('Passwords do not match.');

        $check=db()->prepare('SELECT id FROM users WHERE email=? LIMIT 1');
        $check->execute([$email]);
        if ($check->fetchColumn()) throw new RuntimeException('An account with this email already exists.');

        $hash=password_hash($password,PASSWORD_DEFAULT);
        if ($hash===false) throw new RuntimeException('Unable to create the account.');

        $ins=db()->prepare("INSERT INTO users(name,email,password_hash,role,active) VALUES(?,?,?,'Staff',0)");
        $ins->execute([$name,$email,$hash]);
        $id=(int)db()->lastInsertId();

        $_SESSION['pending_registration_user']=['id'=>$id,'name'=>$name,'email'=>$email,'role'=>'Staff'];
        $_SESSION['pending_registration_created']=time();
        redirect('/auth/biometric.php');
    } catch(Throwable $e) {
        $error=$e->getMessage();
    }
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Create Account — Great Solomon Manpower Services Inc.</title>
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
    <span class="material-symbols-outlined">person_add</span>
    <h1>Create Account</h1>
    <p>Set up your account, then confirm your identity with Face ID.</p>
  </div>
  <?php if($error):?><div class="notice error auth-error"><?=e($error)?></div><?php endif;?>
  <form method="post" class="auth-form" id="registerForm"><?=csrf_field()?>
    <input type="hidden" name="terms_accepted" id="termsAccepted" value="0">
    <div class="field"><label>Full Name</label><input type="text" name="name" maxlength="120" autocomplete="name" required value="<?=e($_POST['name']??'')?>"></div>
    <div class="field"><label>Email Address</label><input type="email" name="email" maxlength="190" autocomplete="email" required value="<?=e($_POST['email']??'')?>"></div>
    <div class="field"><label>Password</label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div>
    <div class="field"><label>Confirm Password</label><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div>
    <details class="auth-terms" id="registerTerms">
      <summary><span>Terms and Conditions</span><span class="material-symbols-outlined" aria-hidden="true">expand_more</span></summary>
      <div class="auth-terms-body">
        <p>By creating an account, you acknowledge that this system is for authorized Great Solomon Manpower Services Inc. use and that your account credentials must remain confidential.</p>
        <p>The system may process personal and sensitive personal information for legitimate company, recruitment, safety, welfare and compliance purposes.</p>
        <div class="auth-terms-accept">
          <label class="auth-checkbox"><input type="checkbox" id="termsCheckbox" required><span>I have read and agree to the Terms and Conditions.</span></label>
          <button type="button" class="gw-btn secondary auth-terms-confirm" id="confirmTerms" disabled><span class="material-symbols-outlined">check_circle</span> Confirm Terms</button>
        </div>
      </div>
    </details>
    <button class="gw-btn primary auth-submit" type="submit" id="registerSubmit" disabled>
      <span class="material-symbols-outlined">face</span> Continue to Face ID
    </button>
  </form>
  <a class="auth-link secondary" href="../auth/login.php">Already have an account? Sign in</a>
  <div class="auth-security-note"><span class="material-symbols-outlined">verified_user</span> New accounts must complete Face ID enrollment before activation.</div>
</div>
</div>
<script>
(function(){
 const c=document.getElementById('termsCheckbox'), ok=document.getElementById('confirmTerms'), accepted=document.getElementById('termsAccepted'), submit=document.getElementById('registerSubmit'), terms=document.getElementById('registerTerms');
 if(!c)return;
 c.addEventListener('change',()=>ok.disabled=!c.checked);
 ok.addEventListener('click',()=>{if(!c.checked)return;accepted.value='1';submit.disabled=false;ok.innerHTML='<span class="material-symbols-outlined">verified</span> Terms Confirmed';ok.classList.add('confirmed');terms.open=false;});
})();
</script>
<script src="../app.js"></script>
</body>
</html>
