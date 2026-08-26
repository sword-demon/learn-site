<?php

/**
 * webman/redis 2.x (illuminate/redis client, phpredis driver).
 * Tokens, captcha, and revocation only. Pool is for Workerman workers.
 */
return [
    'client' => 'phpredis',
    'default' => [
        'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
        'port' => (int) (getenv('REDIS_PORT') ?: 6379),
        'password' => getenv('REDIS_PASSWORD') ?: null,
        'database' => (int) (getenv('REDIS_DB') ?: 0),
        'timeout' => 1.0,
        'read_timeout' => 1.0,
        'persistent' => false,
        'prefix' => '',
        'pool' => [
            'max_connections' => 5,
            'min_connections' => 1,
            'wait_timeout' => 3,
            'idle_timeout' => 60,
            'heartbeat_interval' => 50,
        ],
    ],
];
