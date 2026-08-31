<?php

declare(strict_types=1);

namespace App\support;

/**
 * Read connection pool sizing from environment (008-api-scale-100k).
 */
final class PoolConfig
{
    public static function dbMaxConnections(): int
    {
        return max(1, (int) (getenv('DB_POOL_MAX') ?: 5));
    }

    public static function redisMaxConnections(): int
    {
        return max(1, (int) (getenv('REDIS_POOL_MAX') ?: 5));
    }
}
