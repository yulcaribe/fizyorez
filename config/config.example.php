<?php

return [
    'app' => [
        'name' => 'FizyoRez',
        'url' => 'https://your-domain.com',
        'base_path' => '',
        'timezone' => 'Europe/Istanbul',
    ],
    'db' => [
        'host' => 'localhost',
        'name' => 'database_name',
        'user' => 'database_user',
        'pass' => 'database_password',
        'charset' => 'utf8mb4',
    ],
    'mail' => [
        'from_email' => 'noreply@your-domain.com',
        'from_name' => 'FizyoRez',
    ],
    'security' => [
        'token_secret' => 'replace-with-a-long-random-string',
        'cron_token' => 'replace-with-another-random-string',
    ],
];
