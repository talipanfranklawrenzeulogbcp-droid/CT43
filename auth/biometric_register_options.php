<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') wa_json_response(['ok'=>false,'error'=>'POST required.'],405);
verify_csrf();
$user=$_SESSION['pending_registration_user']??($_SESSION['pending_biometric_user']??null);
if (!$user) wa_json_response(['ok'=>false,'error'=>'Registration session expired.'],401);
$created=(int)($_SESSION['pending_registration_created']??($_SESSION['pending_biometric_created']??0));
if (!$created||time()-$created>900) wa_json_response(['ok'=>false,'error'=>'Registration session expired.'],401);
$challenge=wa_new_challenge();
wa_store_challenge('register',$challenge);
$existing=db()->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=?');
$existing->execute([(int)$user['id']]);
$exclude=[];
while($r=$existing->fetch())$exclude[]=['type'=>'public-key','id'=>(string)$r['credential_id']];
$rpId=wa_rp_id();
wa_json_response(['ok'=>true,'options'=>[
 'challenge'=>wa_b64u_encode($challenge),
 'rp'=>['name'=>'Great Solomon Manpower Services Inc. Core Transaction 4','id'=>$rpId],
 'user'=>['id'=>wa_b64u_encode(hash('sha256','ct4-user-'.(int)$user['id'],true)),'name'=>$user['email'],'displayName'=>$user['name']],
 'pubKeyCredParams'=>[
   ['type'=>'public-key','alg'=>-7]
 ],
 'authenticatorSelection'=>['authenticatorAttachment'=>'platform','residentKey'=>'preferred','userVerification'=>'required'],
 'attestation'=>'none',
 'timeout'=>120000,
 'excludeCredentials'=>$exclude
]]);
