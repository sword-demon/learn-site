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
 * A learner only ever sees commission rows where referrer_learner_id = self,
 * with status filtering; learner_can_view_detail=false hides everything.
 */
final class LearnerCommissionViewTest extends TestCase
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

    public function testLearnerSeesOnlyOwnRecordsWithStatusFilter(): void
    {
        [$referrer, , $orderA, $orderB] = $this->fixtures();
        (new CommissionService())->settleForOrder($orderA);
        (new CommissionService())->settleForOrder($orderB);
        (new CommissionService())->markSettledForOrder($orderB);

        $svc = new CommissionService();
        $all = $svc->listForLearner($referrer, 1, 20, null, true);
        self::assertSame(2, (int) $all['total']);
        self::assertGreaterThan(0, $all['summary']['pending_cents']);
        self::assertGreaterThan(0, $all['summary']['settled_cents']);

        $pending = $svc->listForLearner($referrer, 1, 20, 'pending', true);
        self::assertSame(1, (int) $pending['total']);
        self::assertSame($orderA, (int) $pending['items'][0]['order_id']);

        $settled = $svc->listForLearner($referrer, 1, 20, 'settled', true);
        self::assertSame(1, (int) $settled['total']);
        self::assertSame($orderB, (int) $settled['items'][0]['order_id']);

        // voided rows count into the voided bucket only
        (new CommissionService())->voidForOrder($orderA, 'order_refund');
        $afterVoid = $svc->listForLearner($referrer, 1, 20, null, true);
        self::assertSame(2, (int) $afterVoid['total']);
        self::assertSame(0, $afterVoid['summary']['pending_cents']);
        self::assertGreaterThan(0, $afterVoid['summary']['voided_cents']);
    }

    public function testDetailSwitchOffHidesAmountsAndRecords(): void
    {
        [$referrer, , $orderA] = $this->fixtures();
        (new CommissionService())->settleForOrder($orderA);

        $hidden = (new CommissionService())->listForLearner($referrer, 1, 20, null, false);
        self::assertSame([], $hidden['items']);
        self::assertSame(0, $hidden['total']);
        self::assertSame(0, $hidden['summary']['pending_cents']);
        self::assertSame(0, $hidden['summary']['total_cents']);
    }

    public function testOtherLearnerSeesNothing(): void
    {
        [$referrer, , $orderA] = $this->fixtures();
        (new CommissionService())->settleForOrder($orderA);
        $stranger = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '19' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Db::name('learners')->insert([
            'account_id' => $stranger,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $view = (new CommissionService())->listForLearner($stranger, 1, 20, null, true);
        self::assertSame(0, (int) $view['total']);
        self::assertSame([], $view['items']);
    }

    /** @return array{0: int, 1: int, 2: int, 3: int} referrer, buyer, orderA, orderB */
    private function fixtures(): array
    {
        $now = date('Y-m-d H:i:s');
        $referrer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $referrer, 'created_at' => $now, 'updated_at' => $now]);
        $buyer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '15' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $buyer,
            'referrer_learner_id' => $referrer,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'lcv-' . bin2hex(random_bytes(4)),
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
            'level2_pct' => 0,
            'level3_pct' => 0,
            'base' => 'order_paid',
            'per_order_cap_cents' => 500000,
            'per_learner_course_cap_cents' => 500000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'lcv-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Learner View Course',
            'cover_url' => null,
            'teacher_name' => 'T',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 100,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderA = (int) Db::name('orders')->insertGetId([
            'learner_id' => $buyer,
            'course_id' => $courseId,
            'list_price_snapshot' => 100,
            'sale_price_snapshot' => 100,
            'paid_amount' => 100,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderB = (int) Db::name('orders')->insertGetId([
            'learner_id' => $buyer,
            'course_id' => $courseId,
            'list_price_snapshot' => 200,
            'sale_price_snapshot' => 200,
            'paid_amount' => 200,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$referrer, $buyer, $orderA, $orderB];
    }
}
