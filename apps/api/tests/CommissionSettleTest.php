<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use App\service\DistributionConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class CommissionSettleTest extends TestCase
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

    public function testFiveLevelChainPaysNearestThree(): void
    {
        $ids = $this->chain(5);
        [$a, $b, $c, $d, $e] = $ids;
        $this->enableDistribution();
        $orderId = $this->succeededOrder($e, 100);
        (new CommissionService())->settleForOrder($orderId);
        $rows = Db::name('commission_records')->where('order_id', $orderId)->order('level', 'asc')->select()->toArray();
        self::assertCount(3, $rows);
        self::assertSame($d, (int) $rows[0]['referrer_learner_id']);
        self::assertSame(1, (int) $rows[0]['level']);
        self::assertSame($c, (int) $rows[1]['referrer_learner_id']);
        self::assertSame(2, (int) $rows[1]['level']);
        self::assertSame($b, (int) $rows[2]['referrer_learner_id']);
        self::assertSame(3, (int) $rows[2]['level']);
        $referrers = array_map(fn ($r) => (int) $r['referrer_learner_id'], $rows);
        self::assertNotContains($a, $referrers);
        self::assertSame('pending', $rows[0]['status']);
    }

    public function testMarkSettledAfterWindow(): void
    {
        $ids = $this->chain(2);
        $this->enableDistribution();
        $orderId = $this->succeededOrder($ids[1], 50);
        $svc = new CommissionService();
        $svc->settleForOrder($orderId);
        $svc->markSettledForOrder($orderId);
        $status = Db::name('commission_records')->where('order_id', $orderId)->value('status');
        self::assertSame('settled', $status);
    }

    public function testCloseExpiredRefundWindowsSettlesOldOrdersOnly(): void
    {
        $ids = $this->chain(2);
        $this->enableDistribution();
        $freshId = $this->succeededOrder($ids[1], 50);
        $oldId = $this->succeededOrder($ids[1], 40);
        Db::name('orders')->where('id', $oldId)->update([
            'succeeded_at' => (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
                ->modify('-8 days')
                ->format('Y-m-d H:i:s'),
        ]);
        $svc = new CommissionService();
        $svc->settleForOrder($freshId);
        $svc->settleForOrder($oldId);
        $closed = (new \App\service\OrderService(
            new \App\service\EntitlementService(),
            new \App\support\payment\FakePaymentAdapter(),
        ))->closeExpiredRefundWindows(50);
        self::assertGreaterThanOrEqual(1, $closed);
        self::assertSame('pending', (string) Db::name('commission_records')->where('order_id', $freshId)->value('status'));
        self::assertSame('settled', (string) Db::name('commission_records')->where('order_id', $oldId)->value('status'));
    }

    public function testPerLearnerCourseCapTruncates(): void
    {
        $ids = $this->chain(2);
        $this->enableDistribution();
        $staff = (int) Db::name('accounts')->where('kind', 'staff')->order('id', 'desc')->value('id');
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.50,
            'level2_pct' => 0,
            'level3_pct' => 0,
            'base' => 'order_paid',
            'per_order_cap_cents' => 500000,
            'per_learner_course_cap_cents' => 100,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ]);
        $first = $this->succeededOrder($ids[1], 100);
        $second = $this->succeededOrder($ids[1], 100, (int) Db::name('orders')->where('id', $first)->value('course_id'));
        $svc = new CommissionService();
        $svc->settleForOrder($first);
        $svc->settleForOrder($second);
        $sum = (int) Db::name('commission_records')
            ->where('referrer_learner_id', $ids[0])
            ->sum('amount_cents');
        self::assertSame(100, $sum);
    }

    /** @return list<int> */
    private function chain(int $n): array
    {
        $ids = [];
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $n; $i++) {
            $id = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . ($i + 3) . random_int(10000000, 99999999),
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

    private function enableDistribution(): void
    {
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'dist-staff-' . bin2hex(random_bytes(3)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 50000,
            'per_learner_course_cap_cents' => 50000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ]);
    }

    private function succeededOrder(int $learnerId, float $paid, ?int $courseId = null): int
    {
        $now = date('Y-m-d H:i:s');
        if ($courseId === null) {
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'dist-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Dist Course',
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
        }
        return (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'list_price_snapshot' => $paid,
            'sale_price_snapshot' => $paid,
            'paid_amount' => $paid,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'provider_ref' => 't',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
