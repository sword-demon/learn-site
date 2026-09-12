<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CommissionReplayService;
use App\service\CommissionService;
use App\service\DistributionConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * SC-011 — every stored commission record must be re-derivable from the
 * config snapshot frozen at settlement time.
 */
final class CommissionReplayTest extends TestCase
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

    public function testReplayMatchesStoredAmounts(): void
    {
        $ids = $this->chain(3);
        $this->enable();
        $orderId = $this->succeededOrder($ids[2], 1000.00);
        (new CommissionService())->settleForOrder($orderId);

        $levels = (new CommissionReplayService())->replay($orderId)['levels'];
        self::assertNotEmpty($levels);
        foreach ($levels as $level) {
            self::assertTrue(
                $level['equal'],
                "level {$level['level']}: stored {$level['stored_cents']} vs replayed {$level['replayed_cents']}",
            );
        }
    }

    public function testReplayFlagsTamperedAmount(): void
    {
        $ids = $this->chain(3);
        $this->enable();
        $orderId = $this->succeededOrder($ids[2], 1000.00);
        (new CommissionService())->settleForOrder($orderId);

        Db::name('commission_records')
            ->where('order_id', $orderId)
            ->where('level', 1)
            ->update(['amount_cents' => 999999]);

        $levels = (new CommissionReplayService())->replay($orderId)['levels'];
        $byLevel = [];
        foreach ($levels as $level) {
            $byLevel[(int) $level['level']] = $level;
        }
        self::assertFalse($byLevel[1]['equal']);
        self::assertTrue($byLevel[2]['equal']);
    }

    public function testReplayMissingOrderThrows(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ORDER_COMMISSION_NOT_FOUND');
        (new CommissionReplayService())->replay(99999999);
    }

    /** @return list<int> */
    private function chain(int $n): array
    {
        $ids = [];
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $n; $i++) {
            $id = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . ($i + 4) . random_int(10000000, 99999999),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('learners')->insert([
                'account_id' => $id,
                'referrer_learner_id' => $ids[$i - 1] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $ids[] = $id;
        }
        return $ids;
    }

    private function enable(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'replay-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 500000,
            'per_learner_course_cap_cents' => 500000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ]);
    }

    private function succeededOrder(int $learnerId, float $paid): int
    {
        $now = date('Y-m-d H:i:s');
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'replay-cat-' . bin2hex(random_bytes(2)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => 'Replay Course',
            'cover_url' => null,
            'teacher_name' => 'T',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => $paid,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => $paid,
            'sale_price_snapshot' => $paid,
            'paid_amount' => $paid,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
