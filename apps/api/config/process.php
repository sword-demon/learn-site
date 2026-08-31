<?php

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

$workers = (int) (getenv('WEBMAN_WORKERS') ?: 2);
if ($workers < 1) {
    $workers = 1;
}

$reusePortEnv = getenv('WEBMAN_REUSE_PORT');
if ($reusePortEnv !== false && $reusePortEnv !== '') {
    $reusePort = filter_var($reusePortEnv, FILTER_VALIDATE_BOOLEAN);
} else {
    $reusePort = PHP_OS_FAMILY === 'Linux';
}

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => $workers,
        'user' => '',
        'group' => '',
        'reusePort' => $reusePort,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
    'scheduled_tasks_runner' => [
        'handler' => app\process\ScheduledTaskRunner::class,
    ],
];
