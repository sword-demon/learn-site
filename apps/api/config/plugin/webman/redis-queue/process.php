<?php

use Webman\RedisQueue\Process\Consumer;

$consumers = (int) (getenv('QUEUE_CONSUMERS') ?: 2);
if ($consumers < 1) {
    $consumers = 1;
}

return [
    'redis_consumer' => [
        'handler' => Consumer::class,
        'count' => $consumers,
        'constructor' => [
            'consumer_dir' => app_path() . '/queue/redis',
        ],
    ],
];
