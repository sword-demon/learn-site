<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CommissionReplayService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CommissionReplayServiceTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testReplayComparesStoredAmounts(): void
    {
        $now = date('Y-m-d H:i:s');
        $orderId = 910001;
        Db::name('commission_records')->insert([
            'order_id' => $orderId,
            'course_id' => 1,
            'referee_learner_id' => 2,
            'referrer_learner_id' => 3,
            'level' => 1,
            'amount_cents' => 1000,
            'status' => 'pending',
            'source' => 'system_settle',
            'config_snapshot_json' => json_encode(['level1_pct' => 0.1], JSON_THROW_ON_ERROR),
            'order_paid_cents_snapshot' => 10000,
            'created_at' => $now,
        ]);
        $result = (new CommissionReplayService())->replay($orderId);
        self::assertTrue($result['levels'][0]['equal']);
        self::assertSame(1000, $result['levels'][0]['replayed_cents']);
    }

    public function testReplayMissingOrderThrows(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ORDER_COMMISSION_NOT_FOUND');
        (new CommissionReplayService())->replay(1);
    }
}
