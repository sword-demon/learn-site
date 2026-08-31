<?php

$host = getenv('REDIS_HOST') ?: '127.0.0.1';
$port = (int) (getenv('REDIS_PORT') ?: 6379);
$password = getenv('REDIS_PASSWORD') ?: '';
$db = (int) (getenv('REDIS_DB') ?: 0);

return [
    'default' => [
        'host' => 'redis://' . $host . ':' . $port,
        'options' => [
            'auth' => $password !== '' ? $password : null,
            'db' => $db,
            'max_attempts' => 5,
            'retry_seconds' => 5,
        ],
    ],
];
