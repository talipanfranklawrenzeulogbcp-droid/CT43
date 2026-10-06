<?php
/**
 * JSON endpoint for notifications and feedback threads.
 *   GET  ?action=count                      -> unread badge (passive: does NOT extend the inactivity timer)
 *   GET  ?action=list&filter=&type=&before_id=
 *   GET  ?action=my_feedback                -> signed-in user's feedback + admin replies
 *   GET  ?action=feedback_inbox&status=&before_id=   (admin)
 *   POST action=read|read_all|dismiss|feedback_status (CSRF-protected)
 */
require_once __DIR__.'/helpers.php';
require_once __DIR__.'/service_client.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = (string)($_POST['action'] ?? $_GET['action'] ?? 'count');
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';

if ($action === 'count' && !$isPost) require_login_passive(); else require_login();
$u = current_user();
$notes = service('notifications');
$feedback = service('feedback');

try {
    if ($isPost && !csrf_valid()) { http_response_code(403); throw new RuntimeException('Security token expired. Please refresh the page.'); }
    switch ($action) {
        case 'count':
            $out = ['unread' => $notes->unreadCount($u)]; break;
        case 'list':
            $out = $notes->list($u, [
                'filter' => (string)($_GET['filter'] ?? ''), 'type' => (string)($_GET['type'] ?? ''),
                'before_id' => (int)($_GET['before_id'] ?? 0), 'limit' => 20,
            ]); break;
        case 'my_feedback':
            $out = ['items' => $feedback->mine($u)]; break;
        case 'feedback_inbox':
            $out = $feedback->inbox($u, (string)($_GET['status'] ?? ''), (int)($_GET['before_id'] ?? 0)); break;
        case 'read':
            $notes->markRead($u, (int)($_POST['id'] ?? 0)); $out = ['unread' => $notes->unreadCount($u)]; break;
        case 'read_all':
            $notes->markAllRead($u); $out = ['unread' => 0]; break;
        case 'dismiss':
            $notes->dismiss($u, (int)($_POST['id'] ?? 0)); $out = ['unread' => $notes->unreadCount($u)]; break;
        case 'feedback_status':
            $feedback->setStatus($u, (int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? '')); $out = []; break;
        default:
            http_response_code(400); throw new RuntimeException('Invalid request.');
    }
    echo json_encode(['ok' => true] + $out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if (http_response_code() < 400) http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
