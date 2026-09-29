<?php
// Dev server router: php -S 127.0.0.1:8088 tests/router_dev.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/api/v1')) { require __DIR__ . '/../api/index.php'; return true; }
if ($path === '/' || $path === '/index.php') { require __DIR__ . '/../public/index.php'; return true; }
if (str_starts_with($path, '/public/assets/')) return false;
http_response_code(404); return true;
