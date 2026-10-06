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
$designated=(string)($_SESSION['pending_face_id_credential']??'');
if($designated===''){
  $q=db()->prepare('SELECT credential_id FROM webauthn_credentials WHERE user_id=? ORDER BY id');
  $q->execute([(int)$user['id']]); $rows=$q->fetchAll();
  if(count($rows)!==1) wa_json_response(['ok'=>false,'error'=>'No single registered Face ID is available for this account. Ask an administrator to register the correct Face ID.'],400);
  $designated=(string)$rows[0]['credential_id'];
  db()->prepare('UPDATE users SET face_id_credential_id=? WHERE id=?')->execute([$designated,(int)$user['id']]);
  $_SESSION['pending_face_id_credential']=$designated;
}
$check=db()->prepare('SELECT id FROM webauthn_credentials WHERE user_id=? AND credential_id=? LIMIT 1');
$check->execute([(int)$user['id'],$designated]);
if(!$check->fetchColumn()) wa_json_response(['ok'=>false,'error'=>'The registered Face ID credential is no longer available. Please re-register Face ID.'],400);
$allow=[['type'=>'public-key','id'=>$designated]];
wa_json_response(['ok'=>true,'options'=>[
 'challenge'=>wa_b64u_encode($challenge),
 'rpId'=>wa_rp_id(),
 'allowCredentials'=>$allow,
 'userVerification'=>'required',
 'timeout'=>120000
]]);
