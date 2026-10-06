<?php
require_once __DIR__.'/helpers.php';
header('Content-Type: application/json; charset=utf-8');

if (!current_user()) {
    http_response_code(401);
    echo json_encode(['ok'=>false,'count'=>0]);
    exit;
}

try {
    $u=current_user();
    if (($u['role'] ?? '') === 'Administrator') {
        $q=db()->prepare("SELECT
            (SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type IN ('feedback_reply','data_transfer'))
            +
            (SELECT COUNT(DISTINCT COALESCE(feedback_thread_id,id)) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type='feedback')");
        $q->execute([(int)$u['id'],(int)$u['id']]);
        $stmt=$q;
        $q2=db()->prepare("SELECT id, title, message, created_at FROM admin_notifications WHERE (user_id=? OR user_id IS NULL) AND type IN ('feedback','feedback_reply','data_transfer') ORDER BY id DESC LIMIT 1");
        $q2->execute([(int)$u['id']]);
        $latest=$q2->fetch();
    } else {
        $q=db()->prepare("SELECT COUNT(*) FROM admin_notifications WHERE is_read=0 AND (user_id=? OR user_id IS NULL) AND type IN ('data_transfer','feedback_reply')");
        $q->execute([(int)$u['id']]);
        $stmt=$q;
        $q2=db()->prepare("SELECT id, title, message, created_at FROM admin_notifications WHERE (user_id=? OR user_id IS NULL) AND type IN ('data_transfer','feedback_reply') ORDER BY id DESC LIMIT 1");
        $q2->execute([(int)$u['id']]);
        $latest=$q2->fetch();
    }
    echo json_encode([
        'ok'=>true,
        'count'=>(int)$stmt->fetchColumn(),
        'latest'=>$latest ? [
            'id'=>(int)$latest['id'],
            'title'=>(string)$latest['title'],
            'message'=>(string)$latest['message'],
            'created_at'=>(string)$latest['created_at']
        ] : null
    ], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'count'=>0]);
}
