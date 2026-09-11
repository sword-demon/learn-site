<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class JobDispatcherSendTest extends TestCase
{
    public function testReliableDispatchUsesSynchronousRedisSend(): void
    {
        $path = dirname(__DIR__) . '/app/support/queue/JobDispatcher.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('Webman\\RedisQueue\\Redis::send', $source);
        self::assertStringContainsString('!== true', $source);
        self::assertStringNotContainsString('Webman\\RedisQueue\\Client::send', $source);
    }
}
