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
 * T051 — the US3 compliance spine end to end on one order:
 * a 5-level chain buys → only the nearest three are paid (pending) →
 * the refund window closes and they settle → the order refunds and every
 * record is voided with a system audit trail.
 */
final class DistributionE2ETest extends TestCase
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

    public function testChainSettleWindowRefundLifecycle(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'e2e-dist-' . bin2hex(random_bytes(4)),
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

        // A → B → C → D → E (A is the farthest upline, E buys).
        $chain = [];
        for ($i = 0; $i < 5; $i++) {
            $id = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . ($i + 1) . random_int(10000000, 99999999),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('learners')->insert([
                'account_id' => $id,
                'referrer_learner_id' => $chain[$i - 1] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $chain[] = $id;
        }
        [$a, $b, $c, $d, $e] = $chain;

        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'e2e-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'E2E Chain Course',
            'cover_url' => null,
            'teacher_name' => 'T',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 1000,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $e,
            'course_id' => $courseId,
            'list_price_snapshot' => 1000,
            'sale_price_snapshot' => 1000,
            'paid_amount' => 1000,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $svc = new CommissionService();

        // succeeded hook → pending rows for D (level 1), C (2), B (3) only.
        $svc->settleForOrder($orderId);
        $rows = Db::name('commission_records')
            ->where('order_id', $orderId)
            ->order('level', 'asc')
            ->select()
            ->toArray();
        self::assertCount(3, $rows);
        self::assertSame($d, (int) $rows[0]['referrer_learner_id']);
        self::assertSame($c, (int) $rows[1]['referrer_learner_id']);
        self::assertSame($b, (int) $rows[2]['referrer_learner_id']);
        self::assertSame('pending', (string) $rows[0]['status']);
        self::assertSame(10000, (int) $rows[0]['amount_cents']);
        self::assertSame(5000, (int) $rows[1]['amount_cents']);
        self::assertSame(2000, (int) $rows[2]['amount_cents']);

        // Refund window closes with no refund → pending settles.
        $svc->markSettledForOrder($orderId);
        self::assertSame(
            3,
            (int) Db::name('commission_records')
                ->where('order_id', $orderId)
                ->where('status', 'settled')
                ->count(),
        );

        // Refund afterwards → everything voided, system audit written.
        $svc->voidForOrder($orderId, 'order_refund');
        self::assertSame(
            0,
            (int) Db::name('commission_records')
                ->where('order_id', $orderId)
                ->where('status', '<>', 'voided')
                ->count(),
        );
        self::assertSame(
            'system_refund_void',
            (string) Db::name('commission_records')->where('order_id', $orderId)->value('source'),
        );
        self::assertGreaterThanOrEqual(
            1,
            (int) Db::name('distribution_audit_log')
                ->where('action', 'commission.void_refund')
                ->where('actor_type', 'system')
                ->where('subject_id', $orderId)
                ->count(),
        );
    }
}
