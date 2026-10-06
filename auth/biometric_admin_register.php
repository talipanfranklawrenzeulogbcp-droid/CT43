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
$challenge=wa_take_challenge('admin_register');
$body=json_decode(file_get_contents('php://input'),true);
if(!is_array($body)) wa_json_response(['ok'=>false,'error'=>'Invalid Face ID response.'],400);
try {
  if (($body['type']??'')!=='public-key') throw new RuntimeException('Invalid Face ID credential.');
  $clientJson=wa_verify_client((string)($body['response']['clientDataJSON']??''),'webauthn.create',$challenge);
  $parsed=wa_parse_registration(wa_b64u_decode((string)($body['response']['attestationObject']??'')));
  $credId=wa_b64u_encode($parsed['credential_id']);
  $check=db()->prepare('SELECT id FROM webauthn_credentials WHERE credential_id=? LIMIT 1'); $check->execute([$credId]);
  if($check->fetchColumn()) throw new RuntimeException('This Face ID credential is already registered.');
  $current=db()->prepare('SELECT face_id_credential_id FROM users WHERE id=? LIMIT 1');
  $current->execute([(int)$user['id']]); $currentFace=(string)($current->fetchColumn()??'');
  if($currentFace!=='') throw new RuntimeException('A registered Face ID already exists for this account.');
  db()->prepare('INSERT INTO webauthn_credentials(user_id,credential_id,public_key,sign_count,transports) VALUES(?,?,?,?,?)')->execute([(int)$user['id'],$credId,$parsed['public_key'],(int)$parsed['sign_count'],'internal']);
  db()->prepare('UPDATE users SET active=1, face_id_credential_id=? WHERE id=?')->execute([$credId,(int)$user['id']]);
  audit('System Administration & Security','Face ID Registration','Administrator completed Face ID enrollment for '.(string)$user['email']);
  unset($_SESSION['pending_admin_face_user'],$_SESSION['pending_admin_face_created']);
  wa_json_response(['ok'=>true]);
} catch(Throwable $e) { wa_json_response(['ok'=>false,'error'=>$e->getMessage()],400); }
