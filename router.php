<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if (preg_match('#(?:^|/)\.#', $path) || preg_match('#^/(?:app|config|cron|database)(?:/|$)#i', $path) || preg_match('#^/(?:README|DEPLOYMENT)\.md$#i', $path)) {
    http_response_code(403);
    echo 'Forbidden';
    return true;
}

if ($path !== '/' && is_file($file)) {
    return false;
}

if (str_starts_with($path, '/api/v1')) {
    require __DIR__ . '/api/v1/index.php';
    return true;
}

if ($path === '/admin' || str_starts_with($path, '/admin/')) {
    require __DIR__ . '/admin/index.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
