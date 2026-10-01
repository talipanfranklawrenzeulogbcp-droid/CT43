<?php
// Deployment liveness endpoint. It must never load application/database code.
http_response_code(200);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
echo '{"status":"ok"}';
