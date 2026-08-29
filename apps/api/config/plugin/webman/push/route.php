<?php

use App\service\PushNotificationService;
use App\service\TokenService;
use support\Request;
use Webman\Route;
use Webman\Push\Api;

Route::get('/plugin/webman/push/push.js', function (Request $request) {
    return response()->file(base_path() . '/vendor/webman/push/src/push.js');
});

Route::post(config('plugin.webman.push.app.auth'), function (Request $request) {
    $header = (string) $request->header('authorization', '');
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $matches)) {
        return response('Forbidden', 403);
    }
    $info = (new TokenService())->verifyAccess(trim($matches[1]));
    if ($info === null || $info['kind'] !== TokenService::KIND_LEARNER) {
        return response('Forbidden', 403);
    }
    $channelName = (string) $request->post('channel_name', '');
    $learnerId = (new PushNotificationService())->learnerIdFromChannel($channelName);
    if ($learnerId === null || $learnerId !== (int) $info['account_id']) {
        return response('Forbidden', 403);
    }
    $pusher = new Api(
        str_replace('0.0.0.0', '127.0.0.1', config('plugin.webman.push.app.api')),
        config('plugin.webman.push.app.app_key'),
        config('plugin.webman.push.app.app_secret'),
    );
    return response($pusher->socketAuth($channelName, (string) $request->post('socket_id')));
});

Route::post(parse_url(config('plugin.webman.push.app.channel_hook'), PHP_URL_PATH), function (Request $request) {
    $webhookSignature = $request->header('x-pusher-signature');
    if ($webhookSignature === null || $webhookSignature === '') {
        return response('401 Not authenticated', 401);
    }
    $body = $request->rawBody();
    $expectedSignature = hash_hmac('sha256', $body, config('plugin.webman.push.app.app_secret'), false);
    if ($webhookSignature !== $expectedSignature) {
        return response('401 Not authenticated', 401);
    }
    return 'OK';
});
