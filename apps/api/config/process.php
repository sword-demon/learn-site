<?php

use support\Log;
use support\Request;
use app\process\Http;

global $argv;

$workers = (int) (getenv('WEBMAN_WORKERS') ?: 2);
if ($workers < 1) {
    $workers = 1;
}

return [
    'webman' => [
        'handler' => Http::class,
        'listen' => 'http://0.0.0.0:8787',
        'count' => $workers,
        'user' => '',
        'group' => '',
        'reusePort' => false,
        'eventLoop' => '',
        'context' => [],
        'constructor' => [
            'requestClass' => Request::class,
            'logger' => Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
    'monitor' => [
        'handler' => app\process\Monitor::class,
        'reloadable' => false,
        'constructor' => [
            'monitorDir' => array_merge([
                app_path(),
                config_path(),
                base_path() . '/process',
                base_path() . '/support',
                base_path() . '/resource',
                base_path() . '/.env',
            ], glob(base_path() . '/plugin/*/app') ?: [], glob(base_path() . '/plugin/*/config') ?: []),
            'monitorExtensions' => [
                'php', 'html', 'htm', 'env',
            ],
            'options' => [
                'enable_file_monitor' => false,
                'enable_memory_monitor' => DIRECTORY_SEPARATOR === '/',
            ],
        ],
    ],
];
