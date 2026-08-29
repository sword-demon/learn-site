<?php
return [
    'enable'       => true,
    'websocket'    => 'websocket://0.0.0.0:3131',
    'api'          => 'http://0.0.0.0:3232',
    'app_key'      => getenv('PUSH_APP_KEY') ?: '',
    'app_secret'   => getenv('PUSH_APP_SECRET') ?: '',
    'channel_hook' => 'http://127.0.0.1:8787/plugin/webman/push/hook',
    'auth'         => '/plugin/webman/push/auth',
];
