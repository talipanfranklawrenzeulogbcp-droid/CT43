<?php
require_once __DIR__.'/../../includes/helpers.php';
require_login();
$id=(int)($_GET['id']??0);
if($id<=0){ http_response_code(404); exit; }
try {
    $q=db()->prepare('SELECT image_type,image_data FROM assets WHERE id=? LIMIT 1');
    $q->execute([$id]); $row=$q->fetch();
    if(!$row || empty($row['image_data']) || empty($row['image_type'])){ http_response_code(404); exit; }
    $type=(string)$row['image_type'];
    if(!in_array($type,['image/jpeg','image/png','image/webp','image/gif'],true)){ http_response_code(404); exit; }
    header('Content-Type: '.$type); header('Content-Length: '.strlen($row['image_data'])); header('Cache-Control: private, max-age=3600');
    echo $row['image_data'];
} catch(Throwable $e){ http_response_code(500); exit; }
