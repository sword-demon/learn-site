<?php

declare(strict_types=1);

namespace Tests;

use App\service\OrderService;
use PHPUnit\Framework\TestCase;

final class OrderExpiryTest extends TestCase
{
    public function testPendingDeadlineIsTheSingleFifteenMinuteServerRule(): void
    {
        $createdAt = new \DateTimeImmutable('2026-09-04 10:00:00', new \DateTimeZone('Asia/Shanghai'));
        self::assertSame(
            '2026-09-04 10:15:00',
            OrderService::pendingDeadline($createdAt)->format('Y-m-d H:i:s'),
        );
        self::assertTrue(OrderService::isPendingWithin($createdAt, $createdAt->modify('+14 minutes')));
        self::assertFalse(OrderService::isPendingWithin($createdAt, $createdAt->modify('+16 minutes')));
    }
}
