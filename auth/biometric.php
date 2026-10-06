<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';

$reg=$_SESSION['pending_registration_user']??null;
$login=$_SESSION['pending_biometric_user']??null;
$enrollExisting=!empty($_SESSION['biometric_enroll_existing']);
if (!$reg && !$login) redirect('/auth/login.php');
$user=$reg ?: $login;
$isAdminSetup=$enrollExisting && strtolower((string)($user['email']??''))==='adminct4@gmail.com';
$isRegistration=(bool)$reg;
if ($isRegistration) {
    $created=(int)($_SESSION['pending_registration_created']??0);
} else {
    $created=(int)($_SESSION['pending_biometric_created']??0);
}
if (!$created || time()-$created>900) {
    unset($_SESSION['pending_registration_user'],$_SESSION['pending_registration_created'],$_SESSION['pending_biometric_user'],$_SESSION['pending_biometric_created'],$_SESSION['biometric_enroll_existing']);
    redirect('/auth/login.php?reason=expired');
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Face ID — Great Solomon Manpower Services Inc.</title>
<link rel="stylesheet" href="../style.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet">
<style>
.faceid-panel{display:flex;flex-direction:column;align-items:center;gap:14px}
.faceid-camera{width:min(320px,100%);aspect-ratio:4/3;border-radius:22px;background:#17122b;border:1px solid #ddd6fe;overflow:hidden;position:relative;box-shadow:0 18px 40px rgba(76,29,149,.16)}
.faceid-camera video{width:100%;height:100%;object-fit:cover;transform:scaleX(-1);display:block}
.faceid-ring{position:absolute;inset:14% 22%;border:3px solid rgba(255,255,255,.9);border-radius:48% 48% 44% 44%;box-shadow:0 0 0 999px rgba(49,46,129,.14);pointer-events:none}
.faceid-status{width:100%;text-align:center;font-size:13px;line-height:1.5;color:#6b6680}
.faceid-status strong{color:#4c1d95}
.faceid-steps{width:100%;display:grid;gap:8px;margin:4px 0 4px}
.faceid-step{display:flex;align-items:center;gap:9px;padding:9px 11px;border:1px solid #e4ddf4;border-radius:11px;background:#faf9ff;color:#6b6680;font-size:12px}
.faceid-step .material-symbols-outlined{font-size:18px;color:#7c3aed}
.faceid-step.done{border-color:#c4b5fd;background:#f5f3ff;color:#4c1d95}
.faceid-step.active{border-color:#8b5cf6;background:#fff}
.faceid-error{display:none;width:100%;text-align:center}
.faceid-note{font-size:11px;color:#756b8d;text-align:center;line-height:1.5}
</style>
</head>
<body class="auth-body">
<div class="login-page">
<div class="login-card auth-card faceid-card">
  <div class="brand-mark auth-brand">
    <div class="brand-logo-white auth-logo-wrap"><img src="../assets/logo2.svg" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div>
    <div class="auth-brand-copy"><strong>Great Solomon Manpower Services Inc.</strong><div class="small-muted">Core Transaction 4</div></div>
  </div>

  <div class="auth-heading">
    <span class="material-symbols-outlined">face</span>
    <h1><?= $isRegistration ? 'Set Up Face ID' : ($isAdminSetup ? 'Register Administrator Face ID' : 'Verify Face ID') ?></h1>
    <p>
      <?php if($isRegistration): ?>
        One quick blink is required before Face ID confirmation. Your actual biometric data stays on your device.
      <?php elseif($enrollExisting): ?>
        This is the one-time administrator Face ID setup. Register the device Face ID now; future sign-ins will require this registered credential.
      <?php else: ?>
        Use the Face ID already registered for this account to continue to the dashboard.
      <?php endif; ?>
    </p>
  </div>

  <div class="faceid-panel">
    <?php if($isRegistration): ?>
      <div class="faceid-camera" id="cameraBox">
        <video id="faceVideo" autoplay muted playsinline></video>
        <div class="faceid-ring"></div>
      </div>
      <div class="faceid-status" id="faceStatus"><strong>Camera permission required.</strong> Allow camera access, center your face, keep your eyes open, then blink once.</div>
      <div class="faceid-steps">
        <div class="faceid-step active" id="stepFace"><span class="material-symbols-outlined">person_search</span><span>One face detected</span></div>
        <div class="faceid-step" id="stepBlink"><span class="material-symbols-outlined">visibility</span><span>Blink once to pass liveness check</span></div>
        <div class="faceid-step" id="stepConfirm"><span class="material-symbols-outlined">face</span><span>Confirm with Face ID</span></div>
      </div>
    <?php endif; ?>

    <div class="notice error faceid-error" id="faceError"></div>
    <button class="gw-btn primary auth-submit" type="button" id="faceButton" <?= $isRegistration?'disabled':'' ?>>
      <span class="material-symbols-outlined">face</span>
      <?= $isRegistration ? 'Confirm with Face ID' : ($enrollExisting ? 'Register Face ID' : 'Verify Registered Face ID') ?>
    </button>
    <a class="auth-link secondary" href="../auth/logout.php">Use a different account</a>
    <div class="faceid-note">
      Face verification is performed by your device's secure authenticator. CT4 verifies only the WebAuthn credential already registered to this account; the site does not store or compare raw face images.
    </div>
  </div>
</div>
</div>

<?php if($isRegistration): ?>
<script src="https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh@0.4.1633559619/face_mesh.js"></script>
<?php endif; ?>
<script>
(function(){
  const isRegistration=<?= $isRegistration?'true':'false' ?>;
  const enrollExisting=<?= $enrollExisting?'true':'false' ?>;
  const doRegistration=isRegistration||enrollExisting;
  const csrf=<?= json_encode(csrf_token()) ?>;
  const button=document.getElementById('faceButton');
  const status=document.getElementById('faceStatus');
  const errorBox=document.getElementById('faceError');
  let blinked=!doRegistration, processing=false;

  function showError(msg){ errorBox.textContent=msg; errorBox.style.display='block'; if(status) status.innerHTML='<strong>Face ID could not continue.</strong> '+msg; }
  function setStep(id,done){const el=document.getElementById(id);if(el)el.classList.toggle('done',done);}
  function b64uToBytes(s){s=s.replace(/-/g,'+').replace(/_/g,'/');while(s.length%4)s+='=';const b=atob(s),a=new Uint8Array(b.length);for(let i=0;i<b.length;i++)a[i]=b.charCodeAt(i);return a;}
  function bytesToB64u(a){let s='';const u=new Uint8Array(a);for(let i=0;i<u.length;i++)s+=String.fromCharCode(u[i]);return btoa(s).replace(/\+/g,'-').replace(/\//g,'_').replace(/=+$/,'');}
  function normalizeCreationOptions(o){
    o.challenge=b64uToBytes(o.challenge);
    o.user.id=b64uToBytes(o.user.id);
    (o.excludeCredentials||[]).forEach(c=>c.id=b64uToBytes(c.id));
    return o;
  }
  function normalizeRequestOptions(o){
    o.challenge=b64uToBytes(o.challenge);
    (o.allowCredentials||[]).forEach(c=>c.id=b64uToBytes(c.id));
    return o;
  }
  async function call(url,body){
    const r=await fetch(url,{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(body),credentials:'same-origin'});
    const j=await r.json().catch(()=>({ok:false,error:'Unexpected server response.'}));
    if(!r.ok||!j.ok) throw new Error(j.error||'Face ID request failed.');
    return j;
  }
  async function beginFaceId(){
    if(processing)return; processing=true; button.disabled=true; errorBox.style.display='none';
    try{
      if(!window.PublicKeyCredential) throw new Error('This browser does not support Face ID / passkeys. Use a modern HTTPS browser on a device with Face ID.');
      if(doRegistration){
        const opts=normalizeCreationOptions((await call('biometric_register_options.php',{})).options);
        const cred=await navigator.credentials.create({publicKey:opts});
        if(!cred)throw new Error('Face ID enrollment was cancelled.');
        const response=cred.response;
        await call('biometric_register.php',{
          id:cred.id,rawId:bytesToB64u(cred.rawId),type:cred.type,
          response:{
            clientDataJSON:bytesToB64u(response.clientDataJSON),
            attestationObject:bytesToB64u(response.attestationObject)
          }
        });
      }else{
        const opts=normalizeRequestOptions((await call('biometric_options.php',{})).options);
        const cred=await navigator.credentials.get({publicKey:opts});
        if(!cred)throw new Error('Face ID verification was cancelled.');
        const response=cred.response;
        await call('biometric_verify.php',{
          id:cred.id,rawId:bytesToB64u(cred.rawId),type:cred.type,
          response:{
            clientDataJSON:bytesToB64u(response.clientDataJSON),
            authenticatorData:bytesToB64u(response.authenticatorData),
            signature:bytesToB64u(response.signature),
            userHandle:response.userHandle?bytesToB64u(response.userHandle):null
          }
        });
      }
      window.location.href='../dashboard.php';
    }catch(e){ showError(e.message||'Face ID could not be completed.'); processing=false; button.disabled=false; }
  }

  button.addEventListener('click',beginFaceId);

  if(!isRegistration)return;

  const video=document.getElementById('faceVideo');
  let stream=null, mesh=null, lastClosed=false, openFrames=0, closeFrames=0, faceSeen=false;
  function eyeAspect(lm,a,b,c,d,e,f){
    const p=i=>({x:lm[i].x,y:lm[i].y});
    const dist=(p1,p2)=>Math.hypot(p1.x-p2.x,p1.y-p2.y);
    return (dist(p(b),p(f))+dist(p(c),p(e)))/(2*dist(p(a),p(d))+0.0001);
  }
  async function startCamera(){
    try{
      if(!navigator.mediaDevices?.getUserMedia)throw new Error('Camera access is not available in this browser.');
      stream=await navigator.mediaDevices.getUserMedia({video:{facingMode:'user',width:{ideal:640},height:{ideal:480}},audio:false});
      video.srcObject=stream; await video.play();
      if(typeof FaceMesh==='undefined')throw new Error('The liveness component could not load. Check your internet connection and reload.');
      mesh=new FaceMesh({locateFile:(file)=>`https://cdn.jsdelivr.net/npm/@mediapipe/face_mesh@0.4.1633559619/${file}`});
      mesh.setOptions({maxNumFaces:1,refineLandmarks:true,minDetectionConfidence:.6,minTrackingConfidence:.6});
      mesh.onResults(onResults);
      async function loop(){if(video.readyState>=2&&!processing)await mesh.send({image:video});requestAnimationFrame(loop);}
      loop();
      status.innerHTML='<strong>Look at the camera.</strong> Keep your eyes open. We will wait for one natural blink.';
    }catch(e){showError(e.message);button.disabled=true;}
  }
  function onResults(res){
    if(!res.multiFaceLandmarks||res.multiFaceLandmarks.length!==1){
      faceSeen=false; openFrames=0; closeFrames=0;
      status.innerHTML='<strong>Center your face.</strong> Make sure exactly one face is visible.';
      return;
    }
    faceSeen=true; setStep('stepFace',true);
    const lm=res.multiFaceLandmarks[0];
    const left=eyeAspect(lm,33,160,158,133,153,144);
    const right=eyeAspect(lm,362,385,387,263,373,380);
    const ear=(left+right)/2;
    if(ear>.22){openFrames++; if(lastClosed && openFrames>=2){blinked=true;setStep('stepBlink',true);setStep('stepConfirm',true);button.disabled=false;status.innerHTML='<strong>Blink confirmed.</strong> You can now tap Confirm with Face ID.';}}
    if(ear<.17 && openFrames>=2){closeFrames++;if(closeFrames>=2){lastClosed=true;openFrames=0;status.innerHTML='<strong>Blink detected.</strong> Open your eyes again to continue.';}}
    if(ear>=.17){closeFrames=0;}
  }
  startCamera();
  window.addEventListener('beforeunload',()=>stream?.getTracks().forEach(t=>t.stop()));
})();
</script>
<script src="../app.js"></script>
</body>
</html>
