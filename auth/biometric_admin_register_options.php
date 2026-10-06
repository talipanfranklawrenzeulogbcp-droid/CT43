<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';
if ($_SERVER['REQUEST_METHOD']!=='POST') wa_json_response(['ok'=>false,'error'=>'POST required.'],405);
verify_csrf();
if ((current_user()['role']??'')!=='Administrator') wa_json_response(['ok'=>false,'error'=>'Administrator access is required.'],403);
$user=$_SESSION['pending_admin_face_user']??null;
$created=(int)($_SESSION['pending_admin_face_created']??0);
if (!$user || !$created || time()-$created>900) wa_json_response(['ok'=>false,'error'=>'Face ID enrollment session expired.'],401);
if (strtolower((string)$user['email'])==='adminct4@gmail.com') wa_json_response(['ok'=>false,'error'=>'Complete the primary administrator Face ID setup from the sign-in screen first.'],400);
$challenge=wa_new_challenge(); wa_store_challenge('admin_register',$challenge);
$existing=db()->prepare('SELECT face_id_credential_id FROM users WHERE id=? LIMIT 1');
$existing->execute([(int)$user['id']]); $designated=(string)($existing->fetchColumn()??'');
if($designated!=='') wa_json_response(['ok'=>false,'error'=>'This account already has a registered Face ID.'],400);
$exclude=[];
wa_json_response(['ok'=>true,'options'=>[
 'challenge'=>wa_b64u_encode($challenge),
 'rp'=>['name'=>'Great Solomon Manpower Services Inc. Core Transaction 4','id'=>wa_rp_id()],
 'user'=>['id'=>wa_b64u_encode(hash('sha256','ct4-user-'.(int)$user['id'],true)),'name'=>(string)$user['email'],'displayName'=>(string)$user['name']],
 'pubKeyCredParams'=>[['type'=>'public-key','alg'=>-7]],
 'authenticatorSelection'=>['authenticatorAttachment'=>'platform','residentKey'=>'preferred','userVerification'=>'required'],
 'attestation'=>'none','timeout'=>120000,'excludeCredentials'=>$exclude
]]);
