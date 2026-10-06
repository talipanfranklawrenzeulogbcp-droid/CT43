<?php
declare(strict_types=1);

// Convert uncaught application failures into a controlled response instead of
// leaking a blank Apache 500 page. Full details go to the container stderr log.
set_exception_handler(function (Throwable $e): void {
    error_log(sprintf('[CT4 UNCAUGHT] %s in %s:%d\n%s', $e->getMessage(), $e->getFile(), $e->getLine(), $e->getTraceAsString()));
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=UTF-8');
        header('Cache-Control: no-store');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Core Transaction 4 — Server Error</title><style>body{font-family:Arial,sans-serif;background:#f5f7fa;margin:0;padding:40px;color:#172033}.card{max-width:760px;margin:auto;background:#fff;border-radius:14px;padding:32px;box-shadow:0 8px 30px rgba(0,0,0,.08)}h1{margin-top:0}p{line-height:1.6}.code{font-family:monospace;background:#f0f2f5;padding:12px;border-radius:8px;overflow:auto}</style></head><body><main class="card"><h1>Server error</h1><p>Core Transaction 4 could not complete this request.</p><p>The technical details were written to the server/container error log. Check the deployment technical logs and resolve the underlying dependency or configuration error.</p><div class="code">HTTP 500 · Request ID: '.htmlspecialchars(bin2hex(random_bytes(8)), ENT_QUOTES, 'UTF-8').'</div><p><a href="/health.php">Open health check</a></p></main></body></html>';
    exit;
});
