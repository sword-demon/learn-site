<?php

declare(strict_types=1);

namespace Tests;

use App\service\ScheduleExpressionService;
use App\service\BusinessException;
use PHPUnit\Framework\TestCase;

final class ScheduledTaskExpressionTest extends TestCase
{
    private ScheduleExpressionService $service;

    protected function setUp(): void
    {
        $this->service = new ScheduleExpressionService();
    }

    public function testValidSixFieldExpressionPasses(): void
    {
        self::assertTrue($this->service->isValid('0 30 3 * * *'));
        $preview = $this->service->preview('0 30 3 * * *');
        self::assertTrue($preview['valid']);
        self::assertNotNull($preview['next_run_at']);
    }

    public function testFiveFieldExpressionRejected(): void
    {
        self::assertFalse($this->service->isValid('30 3 * * *'));
        $preview = $this->service->preview('30 3 * * *');
        self::assertFalse($preview['valid']);
    }

    public function testTooFrequentExpressionRejectedOnSave(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('MIN_INTERVAL_VIOLATION');
        $this->service->validateForSave('*/1 * * * * *');
    }
}
