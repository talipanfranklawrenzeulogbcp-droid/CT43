<?php
declare(strict_types=1);

require_once __DIR__ . '/error_handler.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function e(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf_token'];
}

function verify_csrf_token(?string $token): bool {
    $expected = $_SESSION['csrf_token'] ?? null;
    if ($expected === null || $token === null || $token === '') {
        return false;
    }
    $valid = hash_equals((string)$expected, (string)$token);
    if ($valid) {
        unset($_SESSION['csrf_token']);
    }
    return $valid;
}

function flash(string $type, string $message): void {
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
}

function show_flash(): void {
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return;
    }
    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);
    echo '<div class="notice ' . e($flash['type'] ?? 'info') . '">' . e($flash['message'] ?? '') . '</div>';
}

function audit(string $module, string $action, string $details = ''): void {
    try {
        $user = current_user();
        $stmt = db()->prepare(
            'INSERT INTO audit_logs(user_id,module,action,details) VALUES(?,?,?,?)'
        );
        $stmt->execute([
            !empty($user['id']) ? (int)$user['id'] : null,
            $module,
            $action,
            $details,
        ]);
    } catch (Throwable $e) {
        // Auditing must never turn a successful user action into a 500.
        error_log('CT4 audit write failed: ' . $e->getMessage());
    }
}

function base_url(): string {
    return app_base_path();
}

function url(string $path): string {
    if ($path === '') {
        return rtrim(base_url(), '/');
    }
    if (preg_match('#^(?:https?:)?//#i', $path)) {
        return $path;
    }
    return rtrim(base_url(), '/') . '/' . ltrim($path, '/');
}

function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

function admin_feedback_notifications(): array {
    try {
        $stmt = db()->query(
            "SELECT id,type,sender_name,sender_role,sender_user_id,title,message,is_read,created_at
             FROM admin_notifications
             WHERE type IN ('feedback','data_transfer','feedback_reply')
             ORDER BY created_at DESC,id DESC LIMIT 100"
        );
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('CT4 admin notifications load failed: ' . $e->getMessage());
        return [];
    }
}

function staff_transfer_notifications(): array {
    try {
        $user = current_user();
        if (!$user || ($user['role'] ?? '') !== 'Staff') {
            return [];
        }
        $query = db()->prepare(
            "SELECT id,type,sender_name,sender_role,sender_user_id,title,message,is_read,created_at
             FROM admin_notifications
             WHERE (user_id=? OR user_id IS NULL)
               AND type IN ('data_transfer','feedback_reply')
             ORDER BY created_at DESC,id DESC LIMIT 100"
        );
        $query->execute([(int)$user['id']]);
        return $query->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('CT4 staff notifications load failed: ' . $e->getMessage());
        return [];
    }
}

function sidebar_links(string $section): array {
    $links = [
        ['dashboard', '/dashboard.php', 'dashboard', 'Reports & Dashboard'],
        ['health', '/modules/health_safety/index.php', 'health_and_safety', 'Health, Safety & Welfare'],
        ['legal', '/modules/legal_compliance/index.php', 'gavel', 'Legal & Compliance'],
        ['assets', '/modules/asset_equipment/index.php', 'inventory_2', 'Asset & Equipment'],
        ['ai', '/ai_assistant.php', 'auto_awesome', 'AI System Assistant'],
    ];
    if ((current_user()['role'] ?? '') === 'Administrator') {
        $links[] = ['security', '/modules/system_admin_security/index.php', 'admin_panel_settings', 'System Administration'];
    }
    return $links;
}

function page_header(string $title, string $section = ''): void {
    $user = current_user() ?: [];
    $adminNotifications = (($user['role'] ?? '') === 'Administrator') ? admin_feedback_notifications() : [];
    $staffNotifications = (($user['role'] ?? '') === 'Staff') ? staff_transfer_notifications() : [];
    $initials = strtoupper(substr((string)($user['name'] ?? 'AU'), 0, 2));
    $links = sidebar_links($section);
    $base = base_url();
    ?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="theme-color" content="#312e81">
<title><?= e($title) ?> — Great Solomon Manpower Services Inc.</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Material+Symbols+Outlined:opsz,wght,FILL,GRAD@20..48,400,0,0" rel="stylesheet">
<link rel="stylesheet" href="<?= e(url('/style.css')) ?>">
</head>
<body>
<div id="sidebar-backdrop" aria-hidden="true"></div>
<aside id="sidebar" class="gw-sidebar" aria-label="Primary navigation">
  <div class="gw-brand">
    <div class="gw-brand-icon"><span class="material-symbols-outlined">shield</span></div>
    <div>
      <div class="gw-brand-title">Core Transaction 4</div>
      <div class="gw-brand-subtitle">Governance &amp; Safety</div>
    </div>
  </div>
  <div class="gw-sidebar-section">WORKSPACE</div>
  <nav class="gw-nav">
    <?php foreach ($links as [$key, $href, $icon, $label]): ?>
      <a class="sidebar-main-link <?= $section === $key ? 'active' : '' ?>" href="<?= e(url($href)) ?>"<?= $section === $key ? ' aria-current="page"' : '' ?>>
        <span class="sidebar-main-link-icon material-symbols-outlined"><?= e($icon) ?></span>
        <span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="gw-sidebar-section">SYSTEM</div>
  <nav class="gw-nav">
    <a class="sidebar-subsystem-link" href="<?= e(url('/dashboard.php')) ?>#operational-summary"><span class="sidebar-subsystem-link-icon material-symbols-outlined">monitoring</span><span>Operational Summary</span></a>
    <a class="sidebar-subsystem-link" href="<?= e(url('/dashboard.php')) ?>#staff-activity"><span class="sidebar-subsystem-link-icon material-symbols-outlined">history</span><span>Activity &amp; Reports</span></a>
  </nav>
  <div class="gw-sidebar-footer">
    <span class="gw-status-dot" aria-hidden="true"></span>
    <div><strong>System status</strong><span>Online</span></div>
  </div>
</aside>

<div class="gw-shell">
<header class="gw-topbar">
  <div class="gw-topbar-left">
    <button id="sidebarToggle" class="icon-btn" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
      <span class="material-symbols-outlined">menu</span>
    </button>
    <div class="gw-topbar-title">
      <span class="eyebrow">GREAT SOLOMON MANPOWER SERVICES INC.</span>
      <strong><?= e($title) ?></strong>
    </div>
  </div>
  <div class="gw-user user-menu-wrap">
    <button type="button" class="gw-user-button" onclick="toggleUserMenu()" aria-expanded="false" aria-controls="userMenu">
      <div class="gw-avatar"><?= e($initials) ?></div>
      <div class="gw-user-copy"><strong><?= e($user['name'] ?? 'User') ?></strong><span><?= e($user['role'] ?? '') ?></span></div>
      <span class="material-symbols-outlined user-chevron">expand_more</span>
    </button>
    <div id="userMenu" class="user-dropdown">
      <button type="button" onclick="showNotificationModal()"><span class="material-symbols-outlined">notifications</span>Notifications<?php
        $notifications = (($user['role'] ?? '') === 'Staff') ? $staffNotifications : $adminNotifications;
        $unread = count(array_filter($notifications, static fn(array $n): bool => (int)($n['is_read'] ?? 0) === 0));
        if ($unread > 0): ?><span class="notification-badge"><?= e((string)min($unread, 99)) ?></span><?php endif; ?></button>
      <button type="button" onclick="showDataStorageModal()"><span class="material-symbols-outlined">folder_data</span>Data Storage</button>
      <button type="button" onclick="showArchiveModal()"><span class="material-symbols-outlined">inventory_2</span>Archive</button>
      <button type="button" onclick="showFeedbackModal()"><span class="material-symbols-outlined">feedback</span>Feedback</button>
      <button type="button" onclick="showTermsModal()"><span class="material-symbols-outlined">gavel</span>Terms and Conditions</button>
      <button type="button" onclick="showLogoutModal()"><span class="material-symbols-outlined">logout</span>Logout</button>
    </div>
  </div>
</header>
<main class="gw-main"><div class="page-shell">
<?php
    $GLOBALS['ct4_page_notifications'] = [
        'admin' => $adminNotifications,
        'staff' => $staffNotifications,
        'user' => $user,
        'base' => $base,
    ];
}

function page_footer(): void {
    $state = $GLOBALS['ct4_page_notifications'] ?? ['admin' => [], 'staff' => [], 'user' => [], 'base' => base_url()];
    $feedbackSent = !empty($_SESSION['feedback_sent']);
    unset($_SESSION['feedback_sent'], $_SESSION['feedback_action']);
    ?>
</div></main>
<div id="modalRoot" aria-live="polite"></div>
<script>
window.APP_BASE = <?= json_encode((string)($state['base'] ?? ''), JSON_UNESCAPED_SLASHES) ?>;
window.CURRENT_USER = <?= json_encode($state['user'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.ADMIN_NOTIFICATIONS = <?= json_encode($state['admin'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.STAFF_NOTIFICATIONS = <?= json_encode($state['staff'] ?? [], JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.FEEDBACK_SENT = <?= $feedbackSent ? 'true' : 'false' ?>;
</script>
<script src="<?= e(url('/app.js')) ?>" defer></script>
</div>
</body>
</html>
<?php
}

function module_card(string $href, string $icon, string $title, string $description): void {
    echo '<a class="module-link" href="' . e(url($href)) . '"><div class="gw-sub-card"><div class="mini-icon"><span class="material-symbols-outlined">' . e($icon) . '</span></div><div><strong>' . e($title) . '</strong><p>' . e($description) . '</p></div></div></a>';
}
