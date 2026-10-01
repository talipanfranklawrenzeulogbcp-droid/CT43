<?php
require_once __DIR__ . '/error_handler.php';

require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';

function e($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    $expected = $_SESSION['csrf_token'] ?? null;
    if ($expected === null || $token === null) {
        return false;
    }

    $valid = hash_equals((string)$expected, (string)$token);
    if ($valid) {
        unset($_SESSION['csrf_token']);
    }
    return $valid;
}

function flash(string $type, string $message): void { $_SESSION['flash']=['type'=>$type,'message'=>$message]; }
function show_flash(): void { if (!empty($_SESSION['flash'])) { $f=$_SESSION['flash']; unset($_SESSION['flash']); echo '<div class="notice '.e($f['type']).'">'.e($f['message']).'</div>'; } }
function audit(string $module,string $action,string $details=''): void { try { $u=current_user(); $stmt=db()->prepare('INSERT INTO audit_logs(user_id,module,action,details) VALUES(?,?,?,?)'); $stmt[...]
function base_url(): string { $path=str_replace('\\','/',dirname($_SERVER['SCRIPT_NAME']??'/')); if(str_contains($path,'/modules/')) return preg_replace('#/modules/.*$#','',$path) ?: ''; if(str_co[...]
function url(string $path): string { return rtrim(base_url(),'/').'/'.ltrim($path,'/'); }
function redirect(string $path): void { header('Location: '.url($path)); exit; }
function admin_feedback_notifications(): array {
    try {
        $stmt=db()->query("SELECT id, type, sender_name, sender_role, sender_user_id, title, message, is_read, created_at FROM admin_notifications WHERE type IN ('feedback','data_transfer') ORDER [...]
        return $stmt->fetchAll();
    } catch(Throwable $e) { return []; }
}
function staff_transfer_notifications(): array {
    try {
        $u=current_user();
        if (!$u || ($u['role'] ?? '') !== 'Staff') return [];
        $stmt=$pdo=db();
        $q=$stmt->prepare("SELECT id, type, sender_name, sender_role, sender_user_id, title, message, is_read, created_at FROM admin_notifications WHERE (user_id=? OR user_id IS NULL) AND type IN [...]
        $q->execute([(int)$u['id']]);
        return $q->fetchAll();
    } catch(Throwable $e) { return []; }
}
function page_header(string $title,string $section=''): void {
$u=current_user();
$adminNotifications = (($u['role'] ?? '') === 'Administrator') ? admin_feedback_notifications() : [];
$staffNotifications = (($u['role'] ?? '') === 'Staff') ? staff_transfer_notifications() : [];
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title><?=e($title)?> — Great Solomon Manpower Services Inc.</title>[...]
<button type="button" class="gw-user-button" onclick="toggleUserMenu()" aria-expanded="false">
<div class="gw-avatar"><?=e(strtoupper(substr((string)($u['name']??'AU'),0,2)))?></div>
<div class="gw-user-copy"><strong><?=e($u['name']??'Admin User')?></strong><span><?=e($u['role']??'Administrator')?></span></div>
<span class="material-symbols-outlined user-chevron">expand_more</span>
</button>
<div id="userMenu" class="user-dropdown">
<?php if (($u['role'] ?? '') === 'Administrator'): ?>
<button type="button" onclick="showNotificationModal()"><span class="material-symbols-outlined">notifications</span>Notifications<?php $unread=count(array_filter($adminNotifications,fn($n)=>(int)$[...]
<?php elseif (($u['role'] ?? '') === 'Staff'): ?>
<button type="button" onclick="showNotificationModal()"><span class="material-symbols-outlined">notifications</span>Notifications<?php $unread=count(array_filter($staffNotifications,fn($n)=>(int)$[...]
<?php endif; ?>
<button type="button" onclick="showDataStorageModal()"><span class="material-symbols-outlined">folder_data</span>Data Storage</button><button type="button" onclick="showArchiveModal()"><span class[...]
<button type="button" onclick="showFeedbackModal()"><span class="material-symbols-outlined">feedback</span>Feedback</button>
<button type="button" onclick="showTermsModal()"><span class="material-symbols-outlined">gavel</span>Terms and Conditions</button>
<button type="button" onclick="showLogoutModal()"><span class="material-symbols-outlined">logout</span>Logout</button>
</div>
</div></header><main class="gw-main"><div class="page-shell">
<?php }
function page_footer(): void { $u=current_user() ?: []; $feedbackSent=!empty($_SESSION['feedback_sent']); unset($_SESSION['feedback_sent']); $path=(string)($_SERVER['SCRIPT_NAME']??''); $showModul[...]
function module_card(string $href,string $icon,string $title,string $desc): void { echo '<a class="module-link" href="'.e($href).'"><div class="gw-sub-card"><div class="mini-icon"><span class="mat[...]
