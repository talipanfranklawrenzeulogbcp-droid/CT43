<?php
http_response_code(500);
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store');
$id = defined('CT4_ERROR_ID') ? CT4_ERROR_ID : ($_GET['ref'] ?? '');
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Server Error | CT4</title>
<style>body{margin:0;background:#f3f5f8;color:#202938;font:16px/1.6 system-ui,-apple-system,Segoe UI,sans-serif;display:grid;min-height:100vh;place-items:center}.card{background:#fff;max-width:620px;margin:24px;padding:36px;border-radius:14px;box-shadow:0 8px 32px #15223812}h1{margin:0 0 8px;font-size:1.6rem}p{color:#526071}.ref{background:#f1f4f8;padding:10px 12px;border-radius:8px;font-family:monospace;overflow-wrap:anywhere}.actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:22px}a{display:inline-block;padding:10px 15px;border-radius:8px;background:#164e8a;color:white;text-decoration:none}a.secondary{background:#e8edf3;color:#243246}</style></head>
<body><main class="card"><h1>We couldn’t complete that request</h1><p>The server encountered an unexpected error. The technical details have been recorded in the server log. Please try again shortly or contact your system administrator with this reference.</p>
<?php if ($id !== ''): ?><p>Reference ID</p><div class="ref"><?= htmlspecialchars((string)$id, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
<div class="actions"><a href="/">Return to home</a><a class="secondary" href="/health.php">Check service status</a></div></main></body></html>
