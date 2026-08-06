<?php

declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));

$configPath = BASE_PATH . '/config/config.php';
if (!is_file($configPath)) {
    $configPath = BASE_PATH . '/config/config.example.php';
}

$GLOBALS['config'] = require $configPath;

require_once BASE_PATH . '/app/core.php';

date_default_timezone_set((string) config('app.timezone', 'Europe/Istanbul'));

require_once BASE_PATH . '/app/mailer.php';
require_once BASE_PATH . '/app/management.php';
require_once BASE_PATH . '/app/reservations.php';

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('fizyorez_session');
    session_start();
}
