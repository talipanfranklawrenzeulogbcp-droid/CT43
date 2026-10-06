<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/helpers.php';
require_login();

$id=(int)($_GET['id']??0);
if($id<=0){ http_response_code(404); exit; }

try {
    $q=db()->prepare('SELECT image_type,image_data,image_hash FROM assets WHERE id=? LIMIT 1');
    $q->execute([$id]);
    $row=$q->fetch();
    if(!$row || !is_string($row['image_data']??null) || $row['image_data']==='' || empty($row['image_type'])){
        http_response_code(404); exit;
    }
    $type=(string)$row['image_type'];
    if(!in_array($type,['image/jpeg','image/png','image/webp','image/gif'],true)){ http_response_code(404); exit; }
    $data=$row['image_data'];
    $etag=!empty($row['image_hash']) ? '"'.preg_replace('/[^a-f0-9]/i','',(string)$row['image_hash']).'"' : '"asset-'.$id.'-'.sha1($data).'"';
    if(isset($_SERVER['HTTP_IF_NONE_MATCH']) && trim((string)$_SERVER['HTTP_IF_NONE_MATCH'])===$etag){
        http_response_code(304); exit;
    }
    header('Content-Type: '.$type);
    header('Content-Length: '.strlen($data));
    header('Content-Disposition: inline');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=3600, must-revalidate');
    header('ETag: '.$etag);
    echo $data;
} catch(Throwable $e){ http_response_code(500); exit; }
