<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') wa_json_response(['ok'=>false,'error'=>'POST required.'],405);
verify_csrf();
$user=$_SESSION['pending_biometric_user']??null;
if (!$user) wa_json_response(['ok'=>false,'error'=>'Face ID session expired.'],401);
$challenge=wa_take_challenge('login');
$body=json_decode(file_get_contents('php://input'),true);
try{
  if(!is_array($body)||($body['type']??'')!=='public-key')throw new RuntimeException('Invalid Face ID response.');
  $rawId=(string)($body['rawId']??'');
  $clientB64=(string)($body['response']['clientDataJSON']??'');
  $authB64=(string)($body['response']['authenticatorData']??'');
  $sigB64=(string)($body['response']['signature']??'');
  if($rawId===''||$clientB64===''||$authB64===''||$sigB64==='')throw new RuntimeException('Incomplete Face ID response.');
  $clientJson=wa_verify_client($clientB64,'webauthn.get',$challenge);
  $stmt=db()->prepare('SELECT id,credential_id,public_key,sign_count FROM webauthn_credentials WHERE user_id=? AND credential_id=? LIMIT 1');
  $stmt->execute([(int)$user['id'],$rawId]); $cred=$stmt->fetch();
  if(!$cred)throw new RuntimeException('This Face ID credential is not registered for this account.');
  $newCount=wa_verify_assertion(wa_b64u_decode($authB64),$clientJson,wa_b64u_decode($sigB64),(string)$cred['public_key'],(int)$cred['sign_count']);
  db()->prepare('UPDATE webauthn_credentials SET sign_count=?,last_used_at=NOW() WHERE id=?')->execute([$newCount,(int)$cred['id']]);
  login_user($user);
  $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
  $history->execute([(int)$user['id'],$user['email'],'Success',$_SERVER['REMOTE_ADDR']??'Unknown',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
  audit('System Administration & Security','Face ID Login','Successful Face ID verification');
  unset($_SESSION['pending_biometric_user'],$_SESSION['pending_biometric_created'],$_SESSION['biometric_enroll_existing']);
  wa_json_response(['ok'=>true]);
}catch(Throwable $e){wa_json_response(['ok'=>false,'error'=>$e->getMessage()],400);}
