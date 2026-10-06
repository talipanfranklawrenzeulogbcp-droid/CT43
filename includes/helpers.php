<?php
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function flash(string $type, string $message): void { $_SESSION['flash']=['type'=>$type,'message'=>$message]; }
function show_flash(): void { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="notice '.e($f['type']).'">'.e($f['message']).'</div>'; } }
function audit(string $module,string $action,string $details=''): void { try { $u=current_user(); $stmt=db()->prepare('INSERT INTO audit_logs(user_id,module,action,details) VALUES(?,?,?,?)'); $stmt->execute([$u['id']??null,$module,$action,$details]); } catch(Throwable $e) {} }
function base_url(): string { return app_base_path(); }
function url(string $path): string { return app_url($path); }
function redirect(string $path): never { header('Location: '.url($path)); exit; }
function sync_legacy_feedback_threads(): void {
    // Existing deployments may contain employee feedback only in
    // admin_notifications. Keep those records intact and materialize them into
    // the conversation tables on demand so the admin inbox never loses them.
    try {
        $pdo=db();
        $legacy=$pdo->query("SELECT id,user_id,sender_user_id,sender_name,sender_role,title,message,created_at,reply_to_id,feedback_thread_id FROM admin_notifications WHERE type IN ('feedback','feedback_reply') ORDER BY id ASC")->fetchAll();
        if(!$legacy)return;
        $findThread=$pdo->prepare("SELECT id,user_id FROM feedback_threads WHERE legacy_notification_id=? LIMIT 1");
        $insertThread=$pdo->prepare("INSERT INTO feedback_threads(user_id,subject,category,priority,status,legacy_notification_id,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)");
        $insertMessage=$pdo->prepare("INSERT INTO feedback_messages(thread_id,sender_user_id,sender_name,sender_role,message,created_at) VALUES(?,?,?,?,?,?)");
        $findUserByName=$pdo->prepare("SELECT id FROM users WHERE name=? AND role='Staff' AND active=1 ORDER BY id LIMIT 2");
        $findMessage=$pdo->prepare("SELECT id FROM feedback_messages WHERE thread_id=? AND message=? AND created_at=? AND COALESCE(sender_user_id,0)=COALESCE(?,0) LIMIT 1");
        foreach($legacy as $row){
            $notificationId=(int)$row['id'];
            $baseId=(int)($row['reply_to_id']??0);
            if(($row['type']??'')==='feedback_reply' && $baseId>0){
                $findThread->execute([$baseId]);
                $thread=$findThread->fetch();
                if(!$thread)continue;
                $threadId=(int)$thread['id'];
            } else {
                $findThread->execute([$notificationId]);
                $thread=$findThread->fetch();
                if(!$thread){
                    // admin_notifications.user_id is the administrator recipient.
                    // The employee who submitted the feedback is sender_user_id.
                    $legacyUser=(int)($row['sender_user_id']??0) ?: null;
                    if(!$legacyUser && trim((string)$row['sender_name'])!==''){
                        $findUserByName->execute([trim((string)$row['sender_name'])]);
                        $matches=$findUserByName->fetchAll(PDO::FETCH_COLUMN);
                        if(count($matches)===1)$legacyUser=(int)$matches[0];
                    }
                    $subject=trim((string)($row['title']??'')) ?: 'General Feedback';
                    $insertThread->execute([$legacyUser,$subject,'General Feedback','Medium','New',$notificationId,$row['created_at'],$row['created_at']]);
                    $threadId=(int)$pdo->lastInsertId();
                    $thread=['id'=>$threadId,'user_id'=>$legacyUser];
                } else {
                    $threadId=(int)$thread['id'];
                    
                }
            }
            $senderUserId=(int)($row['sender_user_id']??0) ?: null;
            // Repair legacy threads that were previously created with the admin
            // recipient as user_id or with a missing employee sender ID.
            if(($row['type']??'')==='feedback'){
                try {
                    if($senderUserId){
                        $repair=$pdo->prepare("UPDATE feedback_threads SET user_id=? WHERE id=? AND (user_id IS NULL OR NOT EXISTS (SELECT 1 FROM users eu WHERE eu.id=feedback_threads.user_id AND eu.role='Staff'))");
                        $repair->execute([$senderUserId,$threadId]);
                    }
                } catch(Throwable $ignore) {}
            }
            $findMessage->execute([$threadId,$row['message'],$row['created_at'],$senderUserId]);
            if(!$findMessage->fetchColumn()){
                $insertMessage->execute([$threadId,$senderUserId,$row['sender_name'],$row['sender_role'],$row['message'],$row['created_at']]);
            } else if($senderUserId){
                try {
                    $repairMsg=$pdo->prepare("UPDATE feedback_messages SET sender_user_id=? WHERE thread_id=? AND message=? AND created_at=? AND sender_role='Staff' AND (sender_user_id IS NULL OR sender_user_id=0)");
                    $repairMsg->execute([$senderUserId,$threadId,$row['message'],$row['created_at']]);
                } catch(Throwable $ignore) {}
            }
            // This link is optional for compatibility with databases where the
            // additive notification column is still being provisioned.
            try {
                $link=$pdo->prepare("UPDATE admin_notifications SET feedback_thread_id=? WHERE id=? AND (feedback_thread_id IS NULL OR feedback_thread_id=0)");
                $link->execute([$threadId,$notificationId]);
            } catch(Throwable $ignore) {}
        }
    } catch(Throwable $ignore) {
        // The normal feedback query below remains the source of truth. A
        // provisioning/permission issue must not take down the dashboard.
    }
}
function feedback_threads_for_user(bool $admin=false): array {
    $u=current_user();
    if (!$u) return [];
    if ($admin && ($u['role'] ?? '') !== 'Administrator') return [];
    if (!$admin && ($u['role'] ?? '') !== 'Staff') return [];

    $threads=[];
    // Primary source: dedicated conversation tables.
    try {
        sync_legacy_feedback_threads();
        $sql = "SELECT t.id,t.user_id,t.subject,t.category,t.priority,t.status,t.created_at,t.updated_at,t.resolved_at,
                       COALESCE(u.name,
                           (SELECT m0.sender_name FROM feedback_messages m0 WHERE m0.thread_id=t.id AND m0.sender_role='Staff' ORDER BY m0.id ASC LIMIT 1),
                           'Staff') AS owner_name,
                       (SELECT COUNT(*) FROM feedback_messages m WHERE m.thread_id=t.id) AS message_count,
                       (SELECT m.message FROM feedback_messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_message,
                       (SELECT m.sender_name FROM feedback_messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_sender_name,
                       (SELECT m.sender_role FROM feedback_messages m WHERE m.thread_id=t.id ORDER BY m.id DESC LIMIT 1) AS last_sender_role,
                       (SELECT m.sender_user_id FROM feedback_messages m WHERE m.thread_id=t.id AND m.sender_role='Staff' ORDER BY m.id ASC LIMIT 1) AS owner_message_user_id,
                       t.legacy_notification_id
                FROM feedback_threads t
                LEFT JOIN users u ON u.id=t.user_id
                WHERE t.archived_at IS NULL
                " . ($admin ? "" : "AND (t.user_id=? OR EXISTS (SELECT 1 FROM feedback_messages mx WHERE mx.thread_id=t.id AND mx.sender_user_id=? AND mx.sender_role='Staff'))") . "
                ORDER BY t.updated_at DESC,t.id DESC LIMIT 100";
        $q=db()->prepare($sql);
        $q->execute($admin ? [] : [(int)$u['id'], (int)$u['id']]);
        $threads=$q->fetchAll();
        foreach($threads as &$t){
            $mq=db()->prepare("SELECT id,thread_id,sender_user_id,sender_name,sender_role,message,created_at FROM feedback_messages WHERE thread_id=? ORDER BY id ASC");
            $mq->execute([(int)$t['id']]);
            $t['messages']=$mq->fetchAll();
        }
        unset($t);
    } catch(Throwable $primaryError) {
        // Continue to the legacy reader below. Old installations may not have
        // the additive feedback columns/tables yet.
        $threads=[];
    }

    // IMPORTANT: legacy records must also be read when the primary query
    // succeeds but migration/backfill did not create any dedicated threads.
    // This is the case that previously made the Admin inbox appear empty.
    try {
        $pdo=db();
        $q=$pdo->query("SELECT id,user_id,type,title,message,sender_name,sender_role,sender_user_id,is_read,created_at,reply_to_id,feedback_thread_id
                        FROM admin_notifications
                        WHERE type IN ('feedback','feedback_reply')
                        ORDER BY created_at DESC,id DESC LIMIT 200");
        $rows=$q->fetchAll();
        $base=[]; $replies=[];
        try {
            $rq=$pdo->query("SELECT id,user_id,type,title,message,sender_name,sender_role,is_read,created_at,reply_to_id FROM admin_notifications WHERE type='feedback_reply' ORDER BY id ASC");
            $replies=$rq->fetchAll();
        } catch(Throwable $ignore) { $replies=[]; }
        foreach($rows as $row){
            if(($row['type']??'')==='feedback') $base[(int)$row['id']]=$row;
        }

        $represented=[];
        foreach($threads as $t){
            $legacyId=(int)($t['legacy_notification_id']??0);
            if($legacyId>0)$represented[$legacyId]=true;
        }

        foreach($base as $row){
            $legacyId=(int)$row['id'];
            if(isset($represented[$legacyId]))continue;
            if(!$admin){
                $ownerId=(int)($row['user_id']??0);
                $senderId=(int)($row['sender_user_id']??0);
                if($ownerId!==(int)$u['id'] && $senderId!==(int)$u['id']) continue;
            }
            $messages=[[
                'id'=>0,'thread_id'=>-$legacyId,'sender_user_id'=>null,
                'sender_name'=>$row['sender_name'] ?: 'Employee',
                'sender_role'=>$row['sender_role'] ?: 'Staff',
                'message'=>$row['message'] ?: '', 'created_at'=>$row['created_at']
            ]];
            $lastUpdated=$row['created_at'];
            foreach($replies as $reply){
                if((int)($reply['reply_to_id']??0)!==$legacyId)continue;
                $messages[]=[
                    'id'=>(int)$reply['id'],'thread_id'=>-$legacyId,'sender_user_id'=>null,
                    'sender_name'=>$reply['sender_name'] ?: 'Administrator',
                    'sender_role'=>$reply['sender_role'] ?: 'Administrator',
                    'message'=>$reply['message'] ?: '', 'created_at'=>$reply['created_at']
                ];
                $lastUpdated=$reply['created_at'];
            }
            $threads[]=[
                'id'=>-$legacyId,
                'user_id'=>(int)($row['user_id']??0),
                'subject'=>trim((string)($row['title']??'')) ?: 'General Feedback',
                'category'=>'General Feedback','priority'=>'Medium',
                'status'=>count($messages)>1?'Replied':'New',
                'created_at'=>$row['created_at'],'updated_at'=>$lastUpdated,
                'resolved_at'=>null,
                'owner_name'=>$row['sender_name'] ?: 'Employee',
                'message_count'=>count($messages),
                'last_message'=>$messages[count($messages)-1]['message'],
                'last_sender_name'=>$messages[count($messages)-1]['sender_name'],
                'last_sender_role'=>$messages[count($messages)-1]['sender_role'],
                'owner_message_user_id'=>null,
                'messages'=>$messages,
                'legacy_only'=>true,
                'legacy_notification_id'=>$legacyId
            ];
        }
        if(!$admin){
            foreach($replies as $reply){
                $rid=(int)($reply['id']??0);
                $replyOwner=(int)($reply['user_id']??0);
                if($replyOwner!==(int)$u['id']) continue;
                $threadId=(int)($reply['feedback_thread_id']??0);
                if($threadId>0) continue;
                $baseId=(int)($reply['reply_to_id']??0);
                if($baseId<=0) continue;
                $baseRow=$base[$baseId]??null;
                if(!$baseRow) continue;
                $legacyId=$baseId;
                $exists=false; foreach($threads as $existing){ if((int)($existing['legacy_notification_id']??0)===$legacyId){$exists=true;break;} }
                if($exists) continue;
                $threads[]=[
                    'id'=>-$legacyId,'user_id'=>(int)$u['id'],'subject'=>trim((string)($baseRow['title']??''))?:'General Feedback',
                    'category'=>'General Feedback','priority'=>'Medium','status'=>'Replied','created_at'=>$baseRow['created_at'],'updated_at'=>$reply['created_at'],
                    'resolved_at'=>null,'owner_name'=>$baseRow['sender_name']?:($u['name']??'Employee'),'message_count'=>2,
                    'last_message'=>$reply['message'],'last_sender_name'=>$reply['sender_name']?:'Administrator','last_sender_role'=>$reply['sender_role']?:'Administrator',
                    'owner_message_user_id'=>(int)($baseRow['sender_user_id']??0)?:null,
                    'messages'=>[['id'=>0,'thread_id'=>-$legacyId,'sender_user_id'=>(int)($baseRow['sender_user_id']??0)?:null,'sender_name'=>$baseRow['sender_name']?:'Employee','sender_role'=>$baseRow['sender_role']?:'Staff','message'=>$baseRow['message']?:'','created_at'=>$baseRow['created_at']],['id'=>$rid,'thread_id'=>-$legacyId,'sender_user_id'=>(int)($reply['sender_user_id']??0)?:null,'sender_name'=>$reply['sender_name']?:'Administrator','sender_role'=>$reply['sender_role']?:'Administrator','message'=>$reply['message']?:'','created_at'=>$reply['created_at']]],
                    'legacy_only'=>true,'legacy_notification_id'=>$legacyId
                ];
            }
        }
        usort($threads,static function($a,$b){
            $ta=strtotime((string)($a['updated_at']??''));
            $tb=strtotime((string)($b['updated_at']??''));
            return $tb<=>$ta ?: ((int)($b['id']??0)<=> (int)($a['id']??0));
        });
        return array_slice($threads,0,100);
    } catch(Throwable $legacyError) {
        return $threads;
    }
}
function admin_feedback_notifications(): array {
    // Keep data-transfer notifications in the legacy notification stream; feedback
    // is now represented by dedicated threads and messages.
    try {
        $u=current_user(); if(!$u || ($u['role']??'')!=='Administrator') return [];
        $q=db()->prepare("SELECT n.id,n.type,n.sender_name,n.sender_role,n.sender_user_id,n.title,n.message,n.is_read,n.created_at,n.reply_to_id,n.feedback_thread_id
                          FROM admin_notifications n
                          WHERE (n.user_id=? OR n.user_id IS NULL) AND n.type IN ('feedback','feedback_reply','data_transfer')
                          ORDER BY n.created_at DESC,n.id DESC LIMIT 100");
        $q->execute([(int)$u['id']]);
        $rows=$q->fetchAll();
        // Keep the database history intact but expose one bell item per
        // feedback thread. This also handles installations that previously
        // created one feedback notification per administrator.
        $seen=[]; $out=[];
        foreach($rows as $row){
            if(($row['type']??'')==='feedback'){
                $key='thread:'.((int)($row['feedback_thread_id']??0) ?: 'legacy:'.((int)$row['id']));
                if(isset($seen[$key])) continue;
                $seen[$key]=true;
            }
            $out[]=$row;
        }
        return $out;
    }catch(Throwable $e){return [];}
}
function unread_notification_count(): int {
    try{
        $u=current_user(); if(!$u)return 0;
        if(($u['role']??'')==='Administrator'){
            $q=db()->prepare("SELECT
                (SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type IN ('feedback_reply','data_transfer'))
                +
                (SELECT COUNT(DISTINCT COALESCE(feedback_thread_id,id)) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type='feedback')");
            $q->execute([(int)$u['id'],(int)$u['id']]); return (int)$q->fetchColumn();
        }else{
            $q=db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type IN ('data_transfer','feedback_reply')");
        }
        $q->execute([(int)$u['id']]); return (int)$q->fetchColumn();
    }catch(Throwable $e){return 0;}
}
function staff_transfer_notifications(): array {
    try{
        $u=current_user(); if(!$u || ($u['role']??'')!=='Staff')return [];
        $q=db()->prepare("SELECT n.id,n.type,n.sender_name,n.sender_role,n.sender_user_id,n.title,n.message,n.is_read,n.created_at,n.reply_to_id,n.feedback_thread_id
                          FROM admin_notifications n
                          WHERE (n.user_id=? OR n.user_id IS NULL) AND n.type IN ('data_transfer','feedback_reply')
                          ORDER BY n.created_at DESC,n.id DESC LIMIT 100");
        $q->execute([(int)$u['id']]); return $q->fetchAll();
    }catch(Throwable $e){return [];}
}
function page_header(string $title,string $section=''): void {
$u=current_user();
$adminNotifications = (($u['role'] ?? '') === 'Administrator') ? admin_feedback_notifications() : [];
$staffNotifications = (($u['role'] ?? '') === 'Staff') ? staff_transfer_notifications() : [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> — Great Solomon Manpower Services Inc.</title><link rel="stylesheet" href="<?=e(url('/style.css'))?>"><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Public+Sans:wght@400;500;600;700&family=Material+Symbols+Outlined:FILL@0..1&display=swap" rel="stylesheet"></head><body><div id="sidebar-backdrop"></div><aside id="sidebar" class="gw-sidebar"><div class="gw-brand"><div class="brand-logo-white sidebar-logo-wrap"><img src="<?=e(url('/assets/logo2.svg'))?>" alt="Great Solomon Manpower Services Inc. logo" class="brand-logo-image"></div><div class="gw-brand-copy"><div class="gw-brand-title">Great Solomon Manpower Services Inc.</div><div class="gw-brand-subtitle">Governance &amp; Safety</div></div></div><div class="gw-sidebar-section">CORE TRANSACTION 4</div><div style="margin:0 20px 12px;height:1px;background:rgba(255,255,255,.12)"></div><nav class="gw-nav"><a class="module-link" href="<?=e(url('/dashboard.php'))?>"><button class="<?= $section==='dashboard'?'active':'' ?>"><span class="material-symbols-outlined">dashboard</span><span>Reports, Analysis &amp; Dashboard</span></button></a><a class="module-link" href="<?=e(url('/ai_assistant.php'))?>"><button class="<?= $section==='ai'?'active':'' ?>"><span class="material-symbols-outlined">auto_awesome</span><span>AI System Assistant</span><span class="nav-number">AI</span></button></a><a class="module-link" href="<?=e(url('/modules/health_safety/index.php'))?>"><button class="<?= $section==='health'?'active':'' ?>"><span class="material-symbols-outlined">health_and_safety</span><span>Health, Safety &amp; Welfare</span><span class="nav-number">1</span></button></a><a class="module-link" href="<?=e(url('/modules/legal_compliance/index.php'))?>"><button class="<?= $section==='legal'?'active':'' ?>"><span class="material-symbols-outlined">gavel</span><span>Legal &amp; Compliance</span><span class="nav-number">2</span></button></a><?php if (($u['role'] ?? '') === 'Administrator'): ?><a class="module-link" href="<?=e(url('/modules/system_admin_security/index.php'))?>"><button class="<?= $section==='security'?'active':'' ?>"><span class="material-symbols-outlined">admin_panel_settings</span><span>System Administration &amp; Security</span><span class="nav-number">3</span></button></a><?php endif; ?><a class="module-link" href="<?=e(url('/modules/asset_equipment/index.php'))?>"><button class="<?= $section==='assets'?'active':'' ?>"><span class="material-symbols-outlined">inventory_2</span><span>Asset &amp; Equipment Issuance</span><span class="nav-number">4</span></button></a></nav><div class="gw-sidebar-footer"><div class="gw-status-dot"></div><div><strong>Welcome back, <?=e($u['name']??'User')?></strong><span><?=e($u['role']??'Staff')?></span></div></div></aside><div class="gw-shell"><header class="gw-topbar"><div class="gw-topbar-left"><button id="sidebarToggle" class="icon-btn" title="Toggle sidebar"><span class="material-symbols-outlined">menu_open</span></button><div class="gw-topbar-title"><span class="eyebrow">SERVICE MANAGEMENT &amp; ENTERPRISE RESOURCE SYSTEM</span><strong><?=e($title)?></strong></div></div><div class="gw-user user-menu-wrap">
<?php $topNotificationCount=unread_notification_count(); ?>
<button type="button" class="gw-notification-trigger" onclick="handleNotificationBell()" aria-label="Open notifications" title="Notifications">
<span class="material-symbols-outlined">notifications</span><?php if($topNotificationCount>0): ?><span class="top-notification-badge"><?=e($topNotificationCount>99?'99+':$topNotificationCount)?></span><?php endif; ?>
</button>
<button type="button" class="gw-user-button" onclick="toggleUserMenu()" aria-expanded="false">
<div class="gw-avatar"><?=e(strtoupper(substr((string)($u['name']??'AU'),0,2)))?></div>
<div class="gw-user-copy"><strong><?=e($u['name']??'Admin User')?></strong><span><?=e($u['role']??'Administrator')?></span></div>
<span class="material-symbols-outlined user-chevron">expand_more</span>
</button>
<div id="userMenu" class="user-dropdown">
<button type="button" onclick="showDataStorageModal()"><span class="material-symbols-outlined">folder_data</span>Data Storage</button><button type="button" onclick="showArchiveModal()"><span class="material-symbols-outlined">archive</span>Archive</button>
<?php if (($u['role'] ?? '') === 'Administrator'): ?><button type="button" onclick="showAdminFeedbackModal()"><span class="material-symbols-outlined">feedback</span>Employee Feedback</button><?php elseif (($u['role'] ?? '') === 'Staff'): ?><button type="button" onclick="showFeedbackModal()"><span class="material-symbols-outlined">feedback</span>Feedback</button><?php endif; ?>
<button type="button" onclick="showTermsModal()"><span class="material-symbols-outlined">gavel</span>Terms and Conditions</button>
<button type="button" onclick="showLogoutModal()"><span class="material-symbols-outlined">logout</span>Logout</button>
</div>
</div></header><main class="gw-main"><div class="page-shell">
<?php }
function page_footer(): void {
$u=current_user() ?: []; $feedbackSent=!empty($_SESSION['feedback_sent']); unset($_SESSION['feedback_sent']); $path=(string)($_SERVER['SCRIPT_NAME']??''); $showModuleTop=str_contains($path,'/modules/health_safety/') || str_contains($path,'/modules/legal_compliance/') || str_contains($path,'/modules/system_admin_security/') || str_contains($path,'/modules/asset_equipment/');
$jsFlags=JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_SLASHES;
$feedbackThreads=(($u['role']??'') === 'Administrator') ? feedback_threads_for_user(true) : ((($u['role']??'') === 'Staff') ? feedback_threads_for_user(false) : []);
echo '</div></main></div>'.($showModuleTop ? '<button id="moduleTopButton" class="module-top-button" type="button" aria-label="Go to top" title="Go to top"><span class="material-symbols-outlined">arrow_upward</span></button>' : '').'<div id="modalRoot"></div><script>';
echo 'window.APP_BASE='.json_encode(base_url(),$jsFlags).';';
echo 'window.CSRF_TOKEN='.json_encode(csrf_token(),$jsFlags).';';
echo 'window.CURRENT_USER='.json_encode(["id"=>(int)($u["id"]??0),"name"=>(string)($u["name"]??"User"),"role"=>(string)($u["role"]??"Staff")],$jsFlags).';';
echo 'window.ADMIN_NOTIFICATIONS='.json_encode((($u["role"]??"") === "Administrator") ? admin_feedback_notifications() : [],$jsFlags).';';
echo 'window.STAFF_NOTIFICATIONS='.json_encode((($u["role"]??"") === "Staff") ? staff_transfer_notifications() : [],$jsFlags).';';
echo 'window.FEEDBACK_THREADS='.json_encode($feedbackThreads,$jsFlags).';';
echo 'window.FEEDBACK_SENT='.json_encode($feedbackSent,$jsFlags).';';
echo 'window.CURRENT_PATH='.json_encode($_SERVER['REQUEST_URI']??'',$jsFlags).';';
echo '</script><script src="'.e(url('/app.js')).'"></script></body></html>'; }

function module_card(string $href,string $icon,string $title,string $desc): void { echo '<a class="module-link" href="'.e($href).'"><div class="gw-sub-card"><div class="mini-icon"><span class="material-symbols-outlined">'.e($icon).'</span></div><strong>'.e($title).'</strong><span>'.e($desc).'</span><span class="arrow"><span class="material-symbols-outlined">arrow_forward</span></span></div></a>'; }
