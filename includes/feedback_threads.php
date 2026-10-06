<?php
declare(strict_types=1);
require_once __DIR__.'/helpers.php';
header('Content-Type: application/json; charset=utf-8');
if (!current_user()) { http_response_code(401); echo json_encode(['ok'=>false,'threads'=>[],'message'=>'Feedback could not be loaded. Please verify the database connection and feedback tables.']); exit; }
try {
    $u=current_user();
    $isAdmin=(($u['role']??'')==='Administrator');
    $threads=feedback_threads_for_user($isAdmin);
    echo json_encode(['ok'=>true,'threads'=>$threads], JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'threads'=>[],'message'=>'Feedback could not be loaded. Please verify the database connection and feedback tables.']);
}
