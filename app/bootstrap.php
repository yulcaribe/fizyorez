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

if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
}

require_once BASE_PATH . '/app/audit.php';
require_once BASE_PATH . '/app/authorization.php';
require_once BASE_PATH . '/app/credit_ledger.php';
require_once BASE_PATH . '/app/payments.php';
require_once BASE_PATH . '/app/schedules.php';
require_once BASE_PATH . '/app/clinical.php';
require_once BASE_PATH . '/app/mailer.php';
require_once BASE_PATH . '/app/management.php';
require_once BASE_PATH . '/app/reservations.php';

if (session_status() === PHP_SESSION_NONE && PHP_SAPI !== 'cli') {
    session_name('fizyorez_session');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => app_base_path() ?: '/',
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
