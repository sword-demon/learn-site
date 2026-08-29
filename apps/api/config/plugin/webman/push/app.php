<?php
return [
    'enable'       => true,
    'websocket'    => 'websocket://0.0.0.0:3131',
    'api'          => 'http://0.0.0.0:3232',
    'app_key'      => getenv('PUSH_APP_KEY') ?: '4dbd37a2862226de7b7fc4ce60ddc1d0',
    'app_secret'   => getenv('PUSH_APP_SECRET') ?: '6917dac4a4602e7d8abb533ac594cef2',
    'channel_hook' => 'http://127.0.0.1:8787/plugin/webman/push/hook',
    'auth'         => '/plugin/webman/push/auth',
];
