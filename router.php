<?php
// Router dla wbudowanego serwera PHP: php -S 0.0.0.0:$PORT router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;
if ($path !== '/' && is_file($file)) { return false; }
if (is_file($file . '.php')) { require $file . '.php'; return true; }
if ($path === '/' || $path === '/api' || $path === '/api/') { require __DIR__ . '/index.php'; return true; }
http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'Nie znaleziono: ' . $path]);
