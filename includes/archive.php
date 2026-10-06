<?php
require_once __DIR__.'/helpers.php';
require_login();
$action=(string)($_POST['action'] ?? $_GET['action'] ?? 'list');
if ($_SERVER['REQUEST_METHOD']==='POST') verify_csrf();

if($action==='list'){
    require_admin();
    header('Content-Type: application/json; charset=utf-8');
    $type=trim((string)($_GET['type']??'all'));
    if($type==='all'){
        $rows=db()->query("SELECT id,item_type,item_name,source_table,source_id,deleted_at FROM archive_items ORDER BY deleted_at DESC LIMIT 200")->fetchAll(PDO::FETCH_ASSOC);
    }else{
        $q=db()->prepare("SELECT id,item_type,item_name,source_table,source_id,deleted_at FROM archive_items WHERE item_type=? ORDER BY deleted_at DESC LIMIT 200");
        $q->execute([$type]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    echo json_encode(['ok'=>true,'items'=>$rows],JSON_UNESCAPED_UNICODE);
    exit;
}

if($action==='backup'){
    require_admin();
    $type=trim((string)($_GET['type']??'all'));
    $where=''; $params=[];
    if($type==='feedback'){$where=" WHERE item_type='feedback'";}
    elseif($type!=='all'){$type=preg_replace('/[^a-z0-9_\-]/i','',$type);$where=' WHERE item_type=?';$params=[$type];}
    $q=db()->prepare("SELECT id,item_type,source_table,source_id,item_name,payload,deleted_by,deleted_at FROM archive_items{$where} ORDER BY deleted_at DESC");
    $q->execute($params); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    $filename='ct4-archive-'.$type.'-'.date('Ymd-His').'.json';
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="'.$filename.'"');
    echo json_encode(['backup_version'=>1,'generated_at'=>date(DATE_ATOM),'category'=>$type,'count'=>count($rows),'items'=>$rows],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT);
    exit;
}

if($_SERVER['REQUEST_METHOD']==='POST' && $action==='recover'){
    require_admin();
    $id=(int)($_POST['id']??0);
    $st=db()->prepare('SELECT * FROM archive_items WHERE id=?');
    $st->execute([$id]);
    $a=$st->fetch(PDO::FETCH_ASSOC);
    if(!$a){
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>'Archived item not found.']);
        exit;
    }
    try{
        if($a['item_type']==='feedback'){
            $payload=json_decode((string)$a['payload'],true);
            if(!is_array($payload)) throw new RuntimeException('Archived feedback data is invalid.');
            $pdo=db(); $pdo->beginTransaction();
            $thread=$payload['thread']??null;
            $messages=is_array($payload['messages']??null)?$payload['messages']:[];
            $notifications=is_array($payload['notifications']??null)?$payload['notifications']:[];
            $newThreadId=0; $notificationMap=[];
            if(is_array($thread) && isset($thread['subject'])){
                $cols=['user_id','subject','category','priority','status','legacy_notification_id','created_at','updated_at','resolved_at','resolved_by','archived_at'];
                $vals=[];
                foreach($cols as $c){$vals[]=$thread[$c]??null;}
                // A recovered record is active again, so archived_at must be NULL.
                $vals[array_search('archived_at',$cols,true)]=null;
                // Legacy notification IDs belong to the old deleted rows; restore them after notifications are recreated.
                $vals[array_search('legacy_notification_id',$cols,true)]=null;
                $marks=implode(',',array_fill(0,count($cols),'?'));
                $pdo->prepare('INSERT INTO feedback_threads (`'.implode('`,`',$cols).'`) VALUES ('.$marks.')')->execute($vals);
                $newThreadId=(int)$pdo->lastInsertId();
            }
            if($newThreadId<=0) throw new RuntimeException('Archived feedback thread is missing.');
            $messageMap=[];
            foreach($messages as $m){
                $q=$pdo->prepare('INSERT INTO feedback_messages(thread_id,sender_user_id,sender_name,sender_role,message,created_at) VALUES(?,?,?,?,?,?)');
                $q->execute([$newThreadId,$m['sender_user_id']??null,$m['sender_name']??null,$m['sender_role']??null,$m['message']??'', $m['created_at']??date('Y-m-d H:i:s')]);
                $messageMap[(int)($m['id']??0)]=(int)$pdo->lastInsertId();
            }
            foreach($notifications as $n){
                $oldId=(int)($n['id']??0);
                $q=$pdo->prepare('INSERT INTO admin_notifications(user_id,type,title,message,sender_name,sender_role,sender_user_id,is_read,created_at,reply_to_id,feedback_thread_id) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
                $replyTo=$n['reply_to_id']??null;
                $q->execute([$n['user_id']??null,$n['type']??'feedback',$n['title']??'Employee Feedback',$n['message']??'', $n['sender_name']??null,$n['sender_role']??null,$n['sender_user_id']??null,$n['is_read']??0,$n['created_at']??date('Y-m-d H:i:s'),null,$newThreadId]);
                $notificationMap[$oldId]=(int)$pdo->lastInsertId();
            }
            if($notificationMap && !empty($thread['legacy_notification_id'])){
                $newLegacy=$notificationMap[(int)$thread['legacy_notification_id']]??null;
                if($newLegacy)$pdo->prepare('UPDATE feedback_threads SET legacy_notification_id=? WHERE id=?')->execute([$newLegacy,$newThreadId]);
            }
            $pdo->commit();
            db()->prepare('DELETE FROM archive_items WHERE id=?')->execute([$id]);
            audit('Archive','Recover',(string)$a['item_name']);
            header('Content-Type: application/json; charset=utf-8'); echo json_encode(['ok'=>true,'thread_id'=>$newThreadId]); exit;
        }
        if($a['item_type']==='file'){
            $p=json_decode((string)$a['payload'],true)?:[];
            $userId=(int)(current_user()['id'] ?? $a['deleted_by'] ?? 0);
            if(($a['source_table']??'')==='employee_documents'){
                $p['file_data']=(string)($a['file_data'] ?? '');
                $p['file_type']=(string)($a['file_type'] ?: ($p['file_type'] ?? 'application/octet-stream'));
                $p['file_size']=(int)strlen($p['file_data']);
                $p['uploaded_by']=$userId ?: ($p['uploaded_by'] ?? null);
                $cols=array_keys($p);$marks=implode(',',array_fill(0,count($cols),'?'));
                db()->prepare('INSERT INTO employee_documents (`'.implode('`,`',$cols).'`) VALUES ('.$marks.')')->execute(array_values($p));
            } else {
                $svc=service('storage');
                $svc->save((string)$a['item_name'],(string)($a['file_type'] ?: 'application/octet-stream'),(string)($p['source_branch'] ?? 'Archived Recovery'),$userId,(string)($a['file_data'] ?? ''));
            }
        } else {
            $allowed=[
                'safety_incidents',
                'compliance_obligations',
                'compliance_audits',
                'health_records',
                'assets',
                'asset_issuances',
                'issuance_records',
                'maintenance_records',
                'security_events'
            ];
            $table = (string)$a['source_table'];
            if($table === 'issuance_records') $table = 'asset_issuances';
            if(!in_array($table,$allowed,true)) throw new RuntimeException('This record type cannot be recovered automatically.');
            $row=json_decode((string)$a['payload'],true);
            if(!is_array($row)) throw new RuntimeException('Archived record data is invalid.');
            unset($row['id']);
            $cols=array_keys($row);
            $marks=implode(',',array_fill(0,count($cols),'?'));
            $sql='INSERT INTO `'.$table.'` (`'.implode('`,`',$cols).'`) VALUES ('.$marks.')';
            db()->prepare($sql)->execute(array_values($row));
        }
        db()->prepare('DELETE FROM archive_items WHERE id=?')->execute([$id]);
        audit('Archive','Recover',(string)$a['item_name']);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>true]);
        exit;
    }catch(Throwable $e){
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
        exit;
    }
}

if($_SERVER['REQUEST_METHOD']==='POST' && ($action==='delete' || $action==='purge')){
    require_admin();
    $id=(int)($_POST['id']??0);
    $st=db()->prepare('SELECT item_name FROM archive_items WHERE id=?');
    $st->execute([$id]);
    $name=$st->fetchColumn() ?: ('Item #'.$id);
    db()->prepare('DELETE FROM archive_items WHERE id=?')->execute([$id]);
    audit('Archive','Permanent Delete',(string)$name);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok'=>true]);
    exit;
}

http_response_code(400);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok'=>false,'error'=>'Invalid request.']);
