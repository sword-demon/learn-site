<?php
declare(strict_types=1);

namespace Tests;

use support\Redis;

/**
 * Test shim — swaps the global Redis connection for an in-memory instance.
 * Done by overriding the static singleton on the Redis helper used by the
 * production services.
 *
 * Static state is necessary because Webman's Redis facade resolves the
 * connection lazily; we don't want to fight the framework in a unit test.
 */
final class RedisStub
{
    public static ?InMemoryRedis $instance = null;
    public static bool $failOnce = false;

    public static function install(InMemoryRedis $redis): void
    {
        self::$instance = $redis;
        self::$failOnce = false;
    }

    public static function failNext(): void
    {
        if (self::$instance !== null) {
            self::$instance->downOnNextCall = true;
            self::$instance->stayDown = true;
        }
        self::$failOnce = true;
    }
}