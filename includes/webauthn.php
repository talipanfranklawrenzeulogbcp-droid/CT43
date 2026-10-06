<?php
declare(strict_types=1);

/**
 * Minimal dependency-free WebAuthn implementation for CT4.
 * Stores only WebAuthn credential metadata/public keys; biometric templates
 * remain on the user's platform (e.g. Apple Face ID).
 */

function wa_b64u_encode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}
function wa_b64u_decode(string $data): string {
    $p = strlen($data) % 4;
    if ($p) $data .= str_repeat('=', 4 - $p);
    $out = base64_decode(strtr($data, '-_', '+/'), true);
    if ($out === false) throw new RuntimeException('Invalid WebAuthn base64 data.');
    return $out;
}
function wa_origin(): string {
    if (defined('APP_HTTPS') && APP_HTTPS) {
        $scheme='https';
    } else {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    }
    // HTTP_HOST represents the public host/port and is preferable behind a
    // TLS-terminating reverse proxy, where SERVER_PORT may still be 80.
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    if ($host === '') throw new RuntimeException('Unable to determine the WebAuthn host.');
    return $scheme.'://'.$host;
}
function wa_rp_id(): string {
    $host = strtolower((string)parse_url(wa_origin(), PHP_URL_HOST));
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return $host;
    return $host;
}
function wa_assert_origin(string $origin): void {
    if (!hash_equals(wa_origin(), $origin)) throw new RuntimeException('WebAuthn origin validation failed.');
}
function wa_new_challenge(): string {
    return random_bytes(32);
}
function wa_store_challenge(string $purpose, string $challenge): void {
    $_SESSION['webauthn_'.$purpose.'_challenge'] = wa_b64u_encode($challenge);
    $_SESSION['webauthn_'.$purpose.'_created'] = time();
}
function wa_take_challenge(string $purpose): string {
    $key='webauthn_'.$purpose.'_challenge';
    $created=(int)($_SESSION['webauthn_'.$purpose.'_created'] ?? 0);
    $value=(string)($_SESSION[$key] ?? '');
    unset($_SESSION[$key], $_SESSION['webauthn_'.$purpose.'_created']);
    if ($value === '' || !$created || time()-$created > 300) throw new RuntimeException('The Face ID request expired. Please try again.');
    return wa_b64u_decode($value);
}

/* Small CBOR decoder covering the maps/arrays/byte strings used by WebAuthn. */
function wa_cbor_decode(string $data): mixed {
    $i=0;
    $read=function() use (&$read,&$data,&$i) {
        if (!isset($data[$i])) throw new RuntimeException('Invalid WebAuthn CBOR.');
        $b=ord($data[$i++]); $major=$b>>5; $ai=$b&31;
        $len=function() use (&$data,&$i,&$ai): int {
            if ($ai<24) return $ai;
            if ($ai===24) { if($i+1>strlen($data)) throw new RuntimeException('Invalid CBOR length.'); return ord($data[$i++]); }
            if ($ai===25) { if($i+2>strlen($data)) throw new RuntimeException('Invalid CBOR length.'); $v=unpack('n',substr($data,$i,2))[1]; $i+=2; return $v; }
            if ($ai===26) { if($i+4>strlen($data)) throw new RuntimeException('Invalid CBOR length.'); $v=unpack('N',substr($data,$i,4))[1]; $i+=4; return $v; }
            if ($ai===27) { if($i+8>strlen($data)) throw new RuntimeException('Invalid CBOR length.'); $parts=unpack('N2',substr($data,$i,8)); $i+=8; return (int)($parts[1]*4294967296+$parts[2]); }
            throw new RuntimeException('Unsupported CBOR length.');
        };
        if ($major===0) return $ai===31 ? throw new RuntimeException('Invalid CBOR integer.') : $len();
        if ($major===1) return -1-$len();
        if ($major===2) { $n=$len(); if($i+$n>strlen($data)) throw new RuntimeException('Invalid CBOR bytes.'); $v=substr($data,$i,$n); $i+=$n; return $v; }
        if ($major===3) { $n=$len(); if($i+$n>strlen($data)) throw new RuntimeException('Invalid CBOR text.'); $v=substr($data,$i,$n); $i+=$n; return $v; }
        if ($major===4) { $n=$len(); $a=[]; for($k=0;$k<$n;$k++)$a[]=$read(); return $a; }
        if ($major===5) { $n=$len(); $m=[]; for($k=0;$k<$n;$k++){ $key=$read(); $m[is_int($key)||is_string($key)?$key:(string)$key]=$read(); } return $m; }
        if ($major===6) { $read(); return $read(); }
        if ($major===7) {
            if ($ai===20) return false; if($ai===21)return true; if($ai===22)return null;
            if($ai===25){$i+=2;return null;} if($ai===26){$i+=4;return null;} if($ai===27){$i+=8;return null;}
        }
        throw new RuntimeException('Unsupported WebAuthn CBOR type.');
    };
    $value=$read();
    if ($i!==strlen($data)) throw new RuntimeException('Unexpected data after WebAuthn CBOR.');
    return $value;
}

function wa_json(string $b64): array {
    $json=wa_b64u_decode($b64);
    $v=json_decode($json,true);
    if (!is_array($v)) throw new RuntimeException('Invalid WebAuthn client data.');
    return $v;
}
function wa_verify_client(string $clientDataB64, string $expectedType, string $challenge): string {
    $json=wa_b64u_decode($clientDataB64);
    $data=json_decode($json,true);
    if (!is_array($data) || ($data['type'] ?? '') !== $expectedType) throw new RuntimeException('Invalid Face ID ceremony type.');
    $got=(string)($data['challenge'] ?? '');
    if (!hash_equals(wa_b64u_encode($challenge),$got)) throw new RuntimeException('WebAuthn challenge mismatch.');
    wa_assert_origin((string)($data['origin'] ?? ''));
    return $json;
}
function wa_u32(string $s,int $offset): int {
    $v=unpack('N',substr($s,$offset,4)); if(!$v) throw new RuntimeException('Invalid authenticator data.'); return (int)$v[1];
}
function wa_u16(string $s,int $offset): int {
    $v=unpack('n',substr($s,$offset,2)); if(!$v) throw new RuntimeException('Invalid authenticator data.'); return (int)$v[1];
}

function wa_cose_to_pem(array $cose): string {
    $kty=$cose[1]??null; $alg=$cose[3]??null;
    if ($kty===2 && ($alg===-7 || $alg===-35 || $alg===-36)) {
        $x=$cose[-2]??null; $y=$cose[-3]??null; $crv=$cose[-1]??null;
        if (!is_string($x)||!is_string($y)||$crv!==1) throw new RuntimeException('Unsupported EC WebAuthn credential.');
        $point="\x04".$x.$y;
        $oidCurve="\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07"; // prime256v1
        $algId="\x30\x13\x06\x07\x2A\x86\x48\xCE\x3D\x02\x01\x06\x08\x2A\x86\x48\xCE\x3D\x03\x01\x07";
        $bit="\x03".chr(strlen($point)+1)."\x00".$point;
        $spki="\x30".chr(strlen($algId)+strlen($bit)).$algId.$bit;
        return "-----BEGIN PUBLIC KEY-----\n".chunk_split(base64_encode($spki),64,"\n")."-----END PUBLIC KEY-----\n";
    }
    throw new RuntimeException('Unsupported WebAuthn public-key algorithm.');
}

function wa_parse_registration(string $attestation): array {
    $obj=wa_cbor_decode($attestation);
    if (!is_array($obj) || !isset($obj['authData']) || !is_string($obj['authData'])) throw new RuntimeException('Invalid WebAuthn registration response.');
    $auth=$obj['authData'];
    if (strlen($auth)<37) throw new RuntimeException('Invalid authenticator data.');
    if (!hash_equals(hash('sha256',wa_rp_id(),true),substr($auth,0,32))) throw new RuntimeException('WebAuthn relying-party ID mismatch.');
    $flags=ord($auth[32]);
    if (($flags & 0x01)===0 || ($flags & 0x04)===0) throw new RuntimeException('Face ID/user verification was not confirmed.');
    if (($flags & 0x40)===0) throw new RuntimeException('No credential data was returned by the authenticator.');
    $pos=37;
    if ($pos+16+2>strlen($auth)) throw new RuntimeException('Invalid credential data.');
    $pos+=16; $credLen=wa_u16($auth,$pos); $pos+=2;
    if ($pos+$credLen>strlen($auth)) throw new RuntimeException('Invalid credential ID.');
    $credId=substr($auth,$pos,$credLen); $pos+=$credLen;
    $cose=wa_cbor_decode(substr($auth,$pos));
    if (!is_array($cose)) throw new RuntimeException('Invalid credential public key.');
    return ['credential_id'=>$credId,'public_key'=>wa_cose_to_pem($cose),'sign_count'=>wa_u32($auth,33)];
}

function wa_verify_assertion(string $authenticatorData,string $clientDataJSON,string $signature,string $publicKey,int $storedCount): int {
    if (strlen($authenticatorData)<37) throw new RuntimeException('Invalid Face ID authenticator data.');
    if (!hash_equals(hash('sha256',wa_rp_id(),true),substr($authenticatorData,0,32))) throw new RuntimeException('WebAuthn relying-party ID mismatch.');
    $flags=ord($authenticatorData[32]);
    if (($flags & 0x01)===0 || ($flags & 0x04)===0) throw new RuntimeException('Face ID/user verification was not confirmed.');
    $count=wa_u32($authenticatorData,33);
    $data=$authenticatorData.hash('sha256',$clientDataJSON,true);
    $key=openssl_pkey_get_public($publicKey);
    if ($key===false) throw new RuntimeException('Stored WebAuthn public key is invalid.');
    $alg=defined('OPENSSL_ALGO_SHA256') ? OPENSSL_ALGO_SHA256 : 'sha256';
    $ok=openssl_verify($data,$signature,$key,$alg);
    if ($ok!==1) throw new RuntimeException('Face ID signature verification failed.');
    if ($storedCount>0 && $count>0 && $count<=$storedCount) throw new RuntimeException('Authenticator counter validation failed.');
    return $count;
}
function wa_json_response(array $payload,int $status=200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo json_encode($payload,JSON_UNESCAPED_SLASHES);
    exit;
}
