<?php

declare(strict_types=1);

namespace Tests;

use App\support\ShanghaiTime;
use PHPUnit\Framework\TestCase;

final class ShanghaiTimeTest extends TestCase
{
    public function testNowDatetimeIsShanghaiWallClock(): void
    {
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            ShanghaiTime::nowDatetime(),
        );
    }

    public function testToDatetimeAndIso8601RoundTripASqlValue(): void
    {
        $sql = '2026-09-07 13:03:06';
        self::assertSame($sql, ShanghaiTime::toDatetime($sql));
        self::assertSame('2026-09-07T13:03:06+08:00', ShanghaiTime::toIso8601($sql));
        self::assertSame(
            (new \DateTimeImmutable($sql, ShanghaiTime::timezone()))->getTimestamp(),
            ShanghaiTime::timestamp($sql),
        );
    }

    public function testFromIso8601ConvertsOffsetToShanghaiSql(): void
    {
        self::assertSame('2026-09-07 13:03:06', ShanghaiTime::fromIso8601('2026-09-07T13:03:06+08:00'));
        self::assertSame('2026-09-07 13:03:06', ShanghaiTime::fromIso8601('2026-09-07T05:03:06+00:00'));
    }

    public function testNextAfterIsStrictlyGreaterThanCurrent(): void
    {
        $current = ShanghaiTime::nowDatetime();
        $next = ShanghaiTime::nextAfter($current);
        self::assertGreaterThan($current, $next);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $next);
    }
}
