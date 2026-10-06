<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') wa_json_response(['ok'=>false,'error'=>'POST required.'],405);
verify_csrf();
$user=$_SESSION['pending_biometric_user']??null;
if (!$user) wa_json_response(['ok'=>false,'error'=>'Face ID session expired.'],401);
$created=(int)($_SESSION['pending_biometric_created']??0);
if(!$created||time()-$created>900) wa_json_response(['ok'=>false,'error'=>'Face ID session expired.'],401);
$challenge=wa_new_challenge(); wa_store_challenge('login',$challenge);
$q=db()->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=? ORDER BY id');
$q->execute([(int)$user['id']]); $allow=[];
while($r=$q->fetch())$allow[]=['type'=>'public-key','id'=>(string)$r['credential_id']];
if(!$allow) wa_json_response(['ok'=>false,'error'=>'No Face ID credential is enrolled for this account.'],400);
wa_json_response(['ok'=>true,'options'=>[
 'challenge'=>wa_b64u_encode($challenge),
 'rpId'=>wa_rp_id(),
 'allowCredentials'=>$allow,
 'userVerification'=>'required',
 'timeout'=>120000
]]);
