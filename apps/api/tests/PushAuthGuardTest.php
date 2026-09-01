<?php

declare(strict_types=1);

namespace Tests;

use App\service\TokenService;
use App\support\PushAuthGuard;
use App\support\PushClientScript;
use PHPUnit\Framework\TestCase;

final class PushAuthGuardTest extends TestCase
{
    private InMemoryRedis $redis;
    private TokenService $tokens;
    private PushAuthGuard $guard;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
        $this->tokens = new TokenService();
        $this->guard = new PushAuthGuard($this->tokens);
    }

    public function testAuthorizeAcceptsBearerHeader(): void
    {
        $pair = $this->tokens->issue('42', TokenService::KIND_LEARNER);

        $auth = $this->guard->authorize(
            'Bearer ' . $pair['access_token'],
            null,
            'private-learner-42',
        );

        self::assertSame(['account_id' => 42, 'channel_name' => 'private-learner-42'], $auth);
    }

    public function testAuthorizeAcceptsAccessTokenInPostBody(): void
    {
        $pair = $this->tokens->issue('77', TokenService::KIND_LEARNER);

        $auth = $this->guard->authorize('', $pair['access_token'], 'private-learner-77');

        self::assertSame(['account_id' => 77, 'channel_name' => 'private-learner-77'], $auth);
    }

    public function testAuthorizeRejectsMismatchedChannel(): void
    {
        $pair = $this->tokens->issue('42', TokenService::KIND_LEARNER);

        self::assertNull($this->guard->authorize('', $pair['access_token'], 'private-learner-99'));
    }

    public function testPushClientScriptPatchesAuthHeadersAndAuthData(): void
    {
        $source = <<<'JS'
function __ajax(options){
    if(options.type==='POST'){
        xhr.setRequestHeader("Content-Type", "application/x-www-form-urlencoded");
        xhr.send(params);
    }
}

function createPrivateChannel(channel_name, push)
{
    var channel = new Channel(push.connection, channel_name);
    push.channels[channel_name] = channel;
    channel.subscribeCb = function () {
        __ajax({
            url: push.config.auth,
            type: 'POST',
            data: {channel_name: channel_name, socket_id: push.connection.socket_id},
            success: function (data) {
                data = JSON.parse(data);
                data.channel = channel_name;
                push.connection.send(JSON.stringify({event:"pusher:subscribe", data:data}));
            },
            error: function (e) {
                throw Error(e);
            }
        });
    };
    channel.processSubscribe();
    return channel;
}
JS;
        $patched = PushClientScript::patch($source);

        self::assertStringContainsString('getAuthData', $patched);
        self::assertStringContainsString('getAuthHeader', $patched);
        self::assertStringContainsString('options.headers', $patched);
    }
}
