<?php
declare(strict_types=1);

namespace App\support;

/**
 * Request-id carrier. Generated once per request by RequestLogger middleware
 * and reused in every log line, response envelope, and downstream call.
 */
final class RequestId
{
    private static ?string $current = null;

    public static function set(string $id): void
    {
        self::$current = $id;
    }

    public static function get(): ?string
    {
        return self::$current;
    }

    public static function reset(): void
    {
        self::$current = null;
    }
}