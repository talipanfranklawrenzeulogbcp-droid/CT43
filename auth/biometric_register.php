<?php
declare(strict_types=1);
require_once __DIR__.'/../includes/helpers.php';
require_once __DIR__.'/../includes/webauthn.php';

if ($_SERVER['REQUEST_METHOD']!=='POST') wa_json_response(['ok'=>false,'error'=>'POST required.'],405);
verify_csrf();
$user=$_SESSION['pending_registration_user']??($_SESSION['pending_biometric_user']??null);
if (!$user) wa_json_response(['ok'=>false,'error'=>'Registration session expired.'],401);
$challenge=wa_take_challenge('register');
$body=json_decode(file_get_contents('php://input'),true);
if (!is_array($body)) wa_json_response(['ok'=>false,'error'=>'Invalid Face ID response.'],400);
try{
  if (($body['type']??'')!=='public-key') throw new RuntimeException('Invalid Face ID credential.');
  $clientB64=(string)($body['response']['clientDataJSON']??'');
  $attB64=(string)($body['response']['attestationObject']??'');
  $clientJson=wa_verify_client($clientB64,'webauthn.create',$challenge);
  $parsed=wa_parse_registration(wa_b64u_decode($attB64));
  $credId=wa_b64u_encode($parsed['credential_id']);
  $check=db()->prepare('SELECT id FROM webauthn_credentials WHERE credential_id=? LIMIT 1');
  $check->execute([$credId]);
  if($check->fetchColumn()) throw new RuntimeException('This Face ID credential is already registered.');
  db()->prepare('INSERT INTO webauthn_credentials(user_id,credential_id,public_key,sign_count,transports) VALUES(?,?,?,?,?)')
    ->execute([(int)$user['id'],$credId,$parsed['public_key'],(int)$parsed['sign_count'],'internal']);
  if (isset($_SESSION['pending_registration_user'])) { db()->prepare('UPDATE users SET active=1 WHERE id=?')->execute([(int)$user['id']]); }
  login_user($user);
  $history=db()->prepare('INSERT INTO login_history(user_id,email,status,ip_address,user_agent) VALUES(?,?,?,?,?)');
  $history->execute([(int)$user['id'],$user['email'],'Success',$_SERVER['REMOTE_ADDR']??'Unknown',substr($_SERVER['HTTP_USER_AGENT']??'',0,500)]);
  audit('System Administration & Security','Face ID Registration','Successful Face ID credential registration');
  unset($_SESSION['pending_registration_user'],$_SESSION['pending_registration_created'],$_SESSION['pending_biometric_user'],$_SESSION['pending_biometric_created'],$_SESSION['biometric_enroll_existing']);
  wa_json_response(['ok'=>true]);
}catch(Throwable $e){wa_json_response(['ok'=>false,'error'=>$e->getMessage()],400);}
