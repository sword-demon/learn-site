<?php

declare(strict_types=1);

namespace App\support;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Asia/Shanghai wall-clock helpers for SQL datetime and ISO-8601 wire format.
 */
final class ShanghaiTime
{
    public const ZONE = 'Asia/Shanghai';
    public const DATETIME = 'Y-m-d H:i:s';

    public static function timezone(): DateTimeZone
    {
        return new DateTimeZone(self::ZONE);
    }

    public static function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', self::timezone());
    }

    public static function nowDatetime(): string
    {
        return self::now()->format(self::DATETIME);
    }

    public static function nowTimestamp(): int
    {
        return self::now()->getTimestamp();
    }

    /** Parse a SQL datetime (or ISO-8601) as Asia/Shanghai wall clock. */
    public static function parse(string $datetime): DateTimeImmutable
    {
        try {
            return new DateTimeImmutable($datetime, self::timezone());
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid datetime: ' . $datetime, 0, $exception);
        }
    }

    public static function timestamp(string $datetime): int
    {
        return self::parse($datetime)->getTimestamp();
    }

    /** Normalize to `Y-m-d H:i:s` in Asia/Shanghai. */
    public static function toDatetime(string $datetime): string
    {
        return self::parse($datetime)->format(self::DATETIME);
    }

    /** SQL datetime → ISO-8601 with +08:00. */
    public static function toIso8601(string $datetime): string
    {
        return self::parse($datetime)->format(DateTimeInterface::ATOM);
    }

    /**
     * Convert an ISO-8601 instant to Asia/Shanghai SQL datetime.
     *
     * @throws InvalidArgumentException
     */
    public static function fromIso8601(string $iso8601): string
    {
        try {
            return (new DateTimeImmutable($iso8601))
                ->setTimezone(self::timezone())
                ->format(self::DATETIME);
        } catch (\Exception $exception) {
            throw new InvalidArgumentException('Invalid ISO-8601 datetime', 0, $exception);
        }
    }

    /** Strictly later than `$current` (same-second writes bump +1s). */
    public static function nextAfter(string $current): string
    {
        $now = self::now();
        $parsed = DateTimeImmutable::createFromFormat('!' . self::DATETIME, $current, self::timezone());
        if ($parsed instanceof DateTimeImmutable) {
            $now = max($now, $parsed->modify('+1 second'));
        }
        return $now->format(self::DATETIME);
    }
}
