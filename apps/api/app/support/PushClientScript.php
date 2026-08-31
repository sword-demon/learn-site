<?php

declare(strict_types=1);

namespace App\support;

/**
 * Patches vendor webman/push push.js so private-channel auth can send
 * learner Bearer tokens (vendor __ajax only sets Content-Type).
 */
final class PushClientScript
{
    public static function load(): string
    {
        $path = base_path() . '/vendor/webman/push/src/push.js';
        $js = (string) file_get_contents($path);

        return self::patch($js);
    }

    public static function patch(string $js): string
    {
        $js = str_replace(
            "xhr.setRequestHeader(\"Content-Type\", \"application/x-www-form-urlencoded\");\n        xhr.send(params);",
            "xhr.setRequestHeader(\"Content-Type\", \"application/x-www-form-urlencoded\");\n"
            . "        if (options.headers) {\n"
            . "            for (var hk in options.headers) {\n"
            . "                if (Object.prototype.hasOwnProperty.call(options.headers, hk)) {\n"
            . "                    xhr.setRequestHeader(hk, options.headers[hk]);\n"
            . "                }\n"
            . "            }\n"
            . "        }\n"
            . "        xhr.send(params);",
            $js,
        );

        $js = str_replace(
            <<<'JS'
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
JS,
            <<<'JS'
function createPrivateChannel(channel_name, push)
{
    var channel = new Channel(push.connection, channel_name);
    push.channels[channel_name] = channel;
    channel.subscribeCb = function () {
        var authData = push.config.authData || {};
        var authHeader = push.config.authHeader || null;
        if (typeof push.config.getAuthData === 'function') {
            authData = push.config.getAuthData() || {};
        }
        if (typeof push.config.getAuthHeader === 'function') {
            authHeader = push.config.getAuthHeader() || null;
        }
        __ajax({
            url: push.config.auth,
            type: 'POST',
            data: Object.assign({channel_name: channel_name, socket_id: push.connection.socket_id}, authData),
            headers: authHeader,
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
JS,
            $js,
        );

        return $js;
    }
}
