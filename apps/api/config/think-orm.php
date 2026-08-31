<?php

use App\support\PoolConfig;

/**
 * Business ORM config (Constitution IV).
 *
 * Official plugin: webman/think-orm
 * https://www.workerman.net/doc/webman/db/thinkorm.html
 */
return [
    'default' => 'mysql',
    'connections' => [
        'mysql' => [
            'type' => 'mysql',
            'hostname' => getenv('DB_HOST') ?: 'mysql',
            'database' => getenv('DB_DATABASE') ?: 'learn_site',
            'username' => getenv('DB_USER') ?: 'learn_site',
            'password' => getenv('DB_PASSWORD') ?: '',
            'hostport' => getenv('DB_PORT') ?: '3306',
            'params' => [
                PDO::ATTR_TIMEOUT => 3,
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            ],
            'charset' => 'utf8mb4',
            'prefix' => '',
            'break_reconnect' => true,
            'pool' => [
                'max_connections' => PoolConfig::dbMaxConnections(),
                'min_connections' => 1,
                'wait_timeout' => 3,
                'idle_timeout' => 60,
                'heartbeat_interval' => 50,
            ],
        ],
    ],
    'paginator' => '',
];
