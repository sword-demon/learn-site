<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use App\service\DistributionConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * When the three level ratios sum past per_order_cap_cents the overflow is
 * truncated from level 3 upward (3 → 2 → 1), recorded in the audit log, and
 * never paid later.
 */
final class CommissionCapTruncationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        // Truncation audits carry subject_id = null; clear same-action rows
        // committed outside this transaction so counts stay deterministic.
        Db::name('distribution_audit_log')
            ->where('action', 'commission.settle')
            ->where('reason', '已截断')
            ->delete();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testOverflowTruncatedFromLevelThreeUpward(): void
    {
        $ids = $this->chain(4);
        [$a, $b, $c, $d] = $ids;
        $paid = 100000; // ¥1000
        // 50% + 40% + 30% = 120000 cents > cap 100000 → overflow 20000.
        $this->enable(0.50, 0.40, 0.30, 100000);
        $orderId = $this->succeededOrder($d, $paid / 100);
        (new CommissionService())->settleForOrder($orderId);

        $rows = Db::name('commission_records')
            ->where('order_id', $orderId)
            ->order('level', 'asc')
            ->select()
            ->toArray();
        self::assertCount(3, $rows);
        self::assertSame(50000, (int) $rows[0]['amount_cents']); // level 1 keeps its share
        self::assertSame(40000, (int) $rows[1]['amount_cents']); // level 2 keeps its share
        self::assertSame(10000, (int) $rows[2]['amount_cents']); // level 3 absorbs the overflow

        $total = 0;
        foreach ($rows as $row) {
            $total += (int) $row['amount_cents'];
        }
        self::assertSame(100000, $total, 'truncated commission is never paid later');

        $truncated = Db::name('distribution_audit_log')
            ->where('action', 'commission.settle')
            ->where('reason', '已截断')
            ->select()
            ->toArray();
        self::assertGreaterThanOrEqual(1, count($truncated));
        $after = json_decode((string) $truncated[0]['after_json'], true);
        self::assertSame(3, (int) $after['truncated_level']);
        self::assertSame(20000, (int) $after['cut_cents']);
    }

    public function testWithinCapIsNotTruncated(): void
    {
        $ids = $this->chain(4);
        $paid = 100000;
        $this->enable(0.50, 0.40, 0.30, 200000);
        $orderId = $this->succeededOrder($ids[3], $paid / 100);
        (new CommissionService())->settleForOrder($orderId);
        self::assertSame(
            0,
            (int) Db::name('distribution_audit_log')->where('reason', '已截断')->count(),
        );
        $sum = (int) Db::name('commission_records')->where('order_id', $orderId)->sum('amount_cents');
        self::assertSame(120000, $sum);
    }

    /** @return list<int> */
    private function chain(int $n): array
    {
        $ids = [];
        $now = date('Y-m-d H:i:s');
        for ($i = 0; $i < $n; $i++) {
            $id = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . ($i + 6) . random_int(10000000, 99999999),
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

    private function enable(float $l1, float $l2, float $l3, int $orderCap): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'cap-trunc-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => $l1,
            'level2_pct' => $l2,
            'level3_pct' => $l3,
            'base' => 'order_paid',
            'per_order_cap_cents' => $orderCap,
            'per_learner_course_cap_cents' => 0,
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
            'name' => 'cap-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Cap Course',
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
