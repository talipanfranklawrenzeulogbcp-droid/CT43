<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('/dashboard.php');
verify_csrf();
$u=current_user();
$action=(string)($_POST['action']??'send');
$feedback=trim((string)($_POST['feedback']??''));
$returnTo=trim((string)($_POST['return_to']??''));
if($returnTo==='' || !str_starts_with($returnTo,'/') || str_starts_with($returnTo,'//')) $returnTo='/dashboard.php';
function feedback_json(bool $ok,string $message='',array $extra=[]): never {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok'=>$ok],$message!==''?['message'=>$message]:[],$extra));
    exit;
}
function feedback_ajax(): bool { return strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH']??''))==='xmlhttprequest'; }
function materialize_legacy_feedback(PDO $pdo,int $legacyId): int {
    if($legacyId<=0) throw new RuntimeException('Invalid legacy feedback record.');
    $q=$pdo->prepare("SELECT id,user_id,sender_user_id,title,message,sender_name,sender_role,created_at FROM admin_notifications WHERE id=? AND type='feedback' LIMIT 1 FOR UPDATE");
    $q->execute([$legacyId]); $legacy=$q->fetch();
    if(!$legacy) throw new RuntimeException('The selected employee feedback could not be found.');
    $q=$pdo->prepare("SELECT id FROM feedback_threads WHERE legacy_notification_id=? LIMIT 1 FOR UPDATE");
    $q->execute([$legacyId]); $existing=$q->fetchColumn();
    if($existing)return (int)$existing;
    $owner=(int)($legacy['sender_user_id']??0);
    if($owner<=0 && trim((string)$legacy['sender_name'])!==''){
        $find=$pdo->prepare("SELECT id FROM users WHERE name=? AND role='Staff' AND active=1 ORDER BY id LIMIT 2");
        $find->execute([trim((string)$legacy['sender_name'])]); $matches=$find->fetchAll(PDO::FETCH_COLUMN);
        if(count($matches)===1)$owner=(int)$matches[0];
    }
    $ins=$pdo->prepare("INSERT INTO feedback_threads(user_id,subject,category,priority,status,legacy_notification_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)");
    $ins->execute([$owner?:null,trim((string)$legacy['title'])?:'General Feedback','General Feedback','Medium','New',$legacyId,$legacy['created_at'],$legacy['created_at']]);
    $threadId=(int)$pdo->lastInsertId();
    $msg=$pdo->prepare("INSERT INTO feedback_messages(thread_id,sender_user_id,sender_name,sender_role,message,created_at) VALUES(?,?,?,?,?,?)");
    $msg->execute([$threadId,$owner?:null,$legacy['sender_name'],$legacy['sender_role'],$legacy['message'],$legacy['created_at']]);
    try{$pdo->prepare("UPDATE admin_notifications SET feedback_thread_id=? WHERE id=?")->execute([$threadId,$legacyId]);}catch(Throwable $ignore){}
    return $threadId;
}
/** Archive the complete feedback record before it is removed from active tables. */
function archive_feedback_thread(PDO $pdo,int $threadId,int $deletedBy): int {
    $q=$pdo->prepare("SELECT * FROM feedback_threads WHERE id=? LIMIT 1 FOR UPDATE");
    $q->execute([$threadId]); $thread=$q->fetch(PDO::FETCH_ASSOC);
    if(!$thread) throw new RuntimeException('Feedback thread not found.');
    $q=$pdo->prepare("SELECT * FROM feedback_messages WHERE thread_id=? ORDER BY id ASC");
    $q->execute([$threadId]); $messages=$q->fetchAll(PDO::FETCH_ASSOC);
    $q=$pdo->prepare("SELECT * FROM admin_notifications WHERE feedback_thread_id=? OR id=? OR reply_to_id=? ORDER BY id ASC");
    $legacyId=(int)($thread['legacy_notification_id']??0);
    $q->execute([$threadId,$legacyId?:-1,$legacyId?:-1]); $notifications=$q->fetchAll(PDO::FETCH_ASSOC);
    $payload=json_encode([
        'version'=>1,
        'kind'=>'feedback_thread',
        'thread'=>$thread,
        'messages'=>$messages,
        'notifications'=>$notifications,
    ],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    if($payload===false) throw new RuntimeException('Unable to prepare feedback archive data.');
    $name=trim((string)($thread['subject']??'')) ?: 'Employee Feedback #'.$threadId;
    $ins=$pdo->prepare("INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES('feedback','feedback_threads',?,?,?,?)");
    $ins->execute([$threadId,$name,$payload,$deletedBy?:null]);
    return (int)$pdo->lastInsertId();
}
function archive_feedback_legacy_notification(PDO $pdo,int $legacyId,int $deletedBy): int {
    $q=$pdo->prepare("SELECT * FROM admin_notifications WHERE id=? AND type='feedback' LIMIT 1 FOR UPDATE");
    $q->execute([$legacyId]); $n=$q->fetch(PDO::FETCH_ASSOC);
    if(!$n) throw new RuntimeException('The selected feedback no longer exists.');
    $payload=json_encode(['version'=>1,'kind'=>'legacy_feedback_notification','notifications'=>[$n]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    $ins=$pdo->prepare("INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES('feedback','admin_notifications',?,?,?,?)");
    $ins->execute([$legacyId,trim((string)$n['title'])?:'Employee Feedback #'.$legacyId,$payload,$deletedBy?:null]);
    return (int)$pdo->lastInsertId();
}
try{
    $pdo=db(); $uid=(int)($u['id']??0); $name=trim((string)($u['name']??'User')); $role=(string)($u['role']??'Staff');

    if($action==='send'){
        if($role!=='Staff') throw new RuntimeException('Only employees can submit new feedback.');
        if($feedback==='' || mb_strlen($feedback)>3000) throw new RuntimeException($feedback===''?'Please enter your feedback.':'Feedback must be 3,000 characters or fewer.');
        $category=trim((string)($_POST['category']??'General Feedback'));
        $priority=trim((string)($_POST['priority']??'Medium'));
        $subject=trim((string)($_POST['subject']??''));
        $allowedCategories=['Bug / System Problem','Suggestion','Complaint','Security Concern','Data Problem','General Feedback'];
        $allowedPriorities=['Low','Medium','High','Critical'];
        if(!in_array($category,$allowedCategories,true))$category='General Feedback';
        if(!in_array($priority,$allowedPriorities,true))$priority='Medium';
        if($subject==='')$subject=$category;
        $adminIds=array_map('intval',$pdo->query("SELECT id FROM users WHERE role='Administrator' AND active=1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
        $pdo->beginTransaction();
        $q=$pdo->prepare("INSERT INTO feedback_threads(user_id,subject,category,priority,status) VALUES(?,?,?,?,?)");
        $q->execute([$uid?:null,$subject,$category,$priority,'New']); $threadId=(int)$pdo->lastInsertId();
        $q=$pdo->prepare("INSERT INTO feedback_messages(thread_id,sender_user_id,sender_name,sender_role,message) VALUES(?,?,?,?,?)");
        $q->execute([$threadId,$uid?:null,$name,$role,$feedback]);
        $q=$pdo->prepare("INSERT INTO admin_notifications(user_id,type,title,message,sender_name,sender_role,sender_user_id,feedback_thread_id) VALUES(?,?,?,?,?,?,?,?)");
        $noticeId=null;
        foreach($adminIds as $adminId){
            $q->execute([$adminId,'feedback','New Employee Feedback: '.$subject,$feedback,$name,$role,$uid?:null,$threadId]);
            if($noticeId===null)$noticeId=(int)$pdo->lastInsertId();
        }
        if($noticeId!==null)$pdo->prepare("UPDATE feedback_threads SET legacy_notification_id=? WHERE id=?")->execute([$noticeId,$threadId]);
        audit('System Administration & Security','Submit Feedback',$subject.' — '.$name.' ('.$role.')');
        $pdo->commit();
        $_SESSION['feedback_sent']=true; $_SESSION['feedback_action']='send';
        if(feedback_ajax()) feedback_json(true,'Your feedback was sent to the administrator.',['thread_id'=>$threadId]);
        flash('success','Your feedback was sent to the administrator.'); redirect($returnTo);
    }

    if($action==='staff_delete'){
        if($role!=='Staff') throw new RuntimeException('Only an employee can unsend their own feedback.');
        $threadId=(int)($_POST['thread_id']??0); if($threadId===0) throw new RuntimeException('Invalid feedback thread.');
        $pdo->beginTransaction();
        if($threadId<0){
            $legacyId=abs($threadId); archive_feedback_legacy_notification($pdo,$legacyId,$uid);
            $pdo->prepare("DELETE FROM admin_notifications WHERE id=? OR reply_to_id=?")->execute([$legacyId,$legacyId]);
        }else{
            $q=$pdo->prepare("SELECT id,subject,user_id FROM feedback_threads WHERE id=? AND (user_id=? OR EXISTS (SELECT 1 FROM feedback_messages m WHERE m.thread_id=feedback_threads.id AND m.sender_user_id=? AND m.sender_role='Staff')) AND archived_at IS NULL LIMIT 1 FOR UPDATE");
            $q->execute([$threadId,$uid,$uid]); $thread=$q->fetch();
            if(!$thread)throw new RuntimeException('The selected feedback does not belong to your account or no longer exists.');
            archive_feedback_thread($pdo,$threadId,$uid);
            $pdo->prepare("DELETE FROM admin_notifications WHERE feedback_thread_id=?")->execute([$threadId]);
            $pdo->prepare("DELETE FROM feedback_threads WHERE id=?")->execute([$threadId]);
        }
        audit('System Administration & Security','Unsend Feedback','Thread #'.$threadId);
        $pdo->commit();
        if(feedback_ajax()) feedback_json(true,'Feedback unsent and moved to the archive.',['thread_id'=>$threadId]);
        flash('success','Feedback unsent and moved to the archive.'); redirect($returnTo);
    }

    if($action==='delete_feedback'){
        if($role!=='Administrator')throw new RuntimeException('Only an administrator can delete employee feedback.');
        $threadId=(int)($_POST['thread_id']??0); if($threadId===0)throw new RuntimeException('Invalid feedback thread.');
        $pdo->beginTransaction();
        if($threadId<0){
            $legacyId=abs($threadId); archive_feedback_legacy_notification($pdo,$legacyId,$uid);
            $pdo->prepare("DELETE FROM admin_notifications WHERE id=? OR reply_to_id=?")->execute([$legacyId,$legacyId]);
        }else{
            $q=$pdo->prepare("SELECT id,subject FROM feedback_threads WHERE id=? LIMIT 1 FOR UPDATE"); $q->execute([$threadId]); $thread=$q->fetch();
            if(!$thread)throw new RuntimeException('Feedback thread not found.');
            archive_feedback_thread($pdo,$threadId,$uid);
            $pdo->prepare("DELETE FROM admin_notifications WHERE feedback_thread_id=?")->execute([$threadId]);
            $pdo->prepare("DELETE FROM feedback_threads WHERE id=?")->execute([$threadId]);
        }
        audit('System Administration & Security','Delete Employee Feedback','Thread #'.$threadId.' — moved to archive before permanent deletion');
        $pdo->commit();
        if(feedback_ajax()) feedback_json(true,'Feedback moved to the archive.',['thread_id'=>$threadId]);
        flash('success','Feedback moved to the archive.'); redirect($returnTo);
    }

    if($action==='delete_notification'){
        $notificationId=(int)($_POST['notification_id']??0); if($notificationId<=0)throw new RuntimeException('Invalid notification.');
        $q=$pdo->prepare("SELECT * FROM admin_notifications WHERE id=? LIMIT 1"); $q->execute([$notificationId]); $n=$q->fetch(PDO::FETCH_ASSOC);
        if(!$n)throw new RuntimeException('Notification not found.');
        if((int)$n['user_id']!==$uid)throw new RuntimeException('You can only delete your own notification.');
        if(($n['type']??'')!=='feedback')throw new RuntimeException('This notification cannot be deleted from the feedback workflow.');
        $payload=json_encode(['version'=>1,'kind'=>'feedback_notification','notifications'=>[$n]],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $pdo->prepare("INSERT INTO archive_items(item_type,source_table,source_id,item_name,payload,deleted_by) VALUES('feedback','admin_notifications',?,?,?,?)")
            ->execute([$notificationId,(string)($n['title']?:'Employee Feedback Notification'),$payload,$uid?:null]);
        $pdo->prepare("DELETE FROM admin_notifications WHERE id=?")->execute([$notificationId]);
        audit('Archive','Delete Feedback Notification','Notification #'.$notificationId.' moved to archive.');
        feedback_json(true,'Notification moved to the archive.',['id'=>$notificationId]);
    }

    throw new RuntimeException('Unknown feedback action.');
}catch(Throwable $e){
    try{if(isset($pdo)&&$pdo->inTransaction())$pdo->rollBack();}catch(Throwable $ignore){}
    error_log('CT4 feedback action failed: '.$e->getMessage());
    if(feedback_ajax()) feedback_json(false,$e->getMessage());
    flash('error',$e->getMessage()); redirect($returnTo);
}
