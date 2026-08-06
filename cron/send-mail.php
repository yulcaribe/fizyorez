<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    $token = (string) ($_GET['token'] ?? '');
    if (!hash_equals((string) config('security.cron_token'), $token)) {
        http_response_code(403);
        exit('Forbidden');
    }
}

$result = Mailer::sendPending(50);

if (PHP_SAPI === 'cli') {
    echo 'Mail queue: ' . json_encode($result, JSON_UNESCAPED_UNICODE) . PHP_EOL;
    exit;
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE);
