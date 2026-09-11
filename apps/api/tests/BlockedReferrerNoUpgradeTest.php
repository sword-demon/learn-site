<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use App\service\DistributionConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class BlockedReferrerNoUpgradeTest extends TestCase
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

    public function testDisabledReferrerStaysPendingBlocked(): void
    {
        $now = date('Y-m-d H:i:s');
        $a = $this->learner('13811111111', $now);
        $b = $this->learner('13822222222', $now, $a);
        Db::name('accounts')->where('id', $a)->update(['status' => 'disabled']);
        $staff = $this->learner('staffx' . random_int(1000, 9999), $now);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.1,
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
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0, 'name' => 'blk-' . bin2hex(random_bytes(2)), 'path' => '/',
            'depth' => 1, 'sort' => 0, 'status' => 'enabled', 'created_at' => $now, 'updated_at' => $now,
        ]);
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => null, 'category_id' => $categoryId, 'title' => 'B',
            'cover_url' => null, 'teacher_name' => 'T', 'summary' => null, 'intro_rich_text' => null,
            'status' => 'published', 'price_mode' => 'paid', 'list_price' => 10, 'sale_price' => 0,
            'sale_start_at' => null, 'sale_end_at' => null, 'created_by_staff_id' => null,
            'created_at' => $now, 'updated_at' => $now,
        ]);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $b, 'course_id' => $courseId, 'list_price_snapshot' => 10,
            'sale_price_snapshot' => 10, 'paid_amount' => 10, 'currency' => 'CNY',
            'status' => 'succeeded', 'provider' => 'fake', 'created_at' => $now, 'updated_at' => $now,
        ]);
        (new CommissionService())->settleForOrder($orderId);
        $row = Db::name('commission_records')->where('order_id', $orderId)->find();
        self::assertSame('pending_blocked', $row['status']);
        self::assertSame($a, (int) $row['referrer_learner_id']);
        self::assertSame(1, (int) Db::name('commission_records')->where('order_id', $orderId)->count());
    }

    private function learner(string $login, string $now, ?int $referrer = null): int
    {
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner', 'login' => $login, 'password_hash' => 'x',
            'must_change_password' => 0, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id, 'referrer_learner_id' => $referrer, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $id;
    }
}
