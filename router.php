<?php

declare(strict_types=1);

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$file = __DIR__ . $path;

if ($path !== '/' && is_file($file)) {
    return false;
}

if (str_starts_with($path, '/api/v1')) {
    require __DIR__ . '/api/v1/index.php';
    return true;
}

require __DIR__ . '/index.php';
return true;
