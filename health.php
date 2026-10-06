<?php
// Lightweight deployment health endpoint. Does not expose credentials or database details.
http_response_code(200);
header('Content-Type: text/plain; charset=utf-8');
echo "CT4_OK";
