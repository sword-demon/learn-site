<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use App\service\DistributionConfigService;
use App\service\DistributionCourseOverrideService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Every distribution write path must land a row in distribution_audit_log:
 * config.update / course.override.update / commission.settle /
 * commission.void_refund (actor=system) / commission.void_admin.
 */
final class DistributionAuditCoverageTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        // Shared dev DB: drop same-action rows committed outside this
        // transaction so per-action counts are deterministic.
        Db::name('distribution_audit_log')->whereIn('action', [
            'config.update',
            'course.override.update',
            'commission.settle',
            'commission.void_refund',
            'commission.void_admin',
        ])->delete();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testEveryWritePathProducesAudit(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'audit-cov-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $config = new DistributionConfigService();

        // 1) config.update
        $config->updateConfig($staff, $this->configInput());
        $this->assertAuditHas('config.update', 'admin');

        // 2) course.override.update
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'audit-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Audit Course',
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
        (new DistributionCourseOverrideService())->upsert($courseId, ['enabled' => true], $staff);
        $this->assertAuditHas('course.override.update', 'admin');

        // 3) commission.settle + 4) commission.void_refund
        $buyer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '12' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $referrer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '16' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $referrer, 'created_at' => $now, 'updated_at' => $now]);
        Db::name('learners')->insert([
            'account_id' => $buyer,
            'referrer_learner_id' => $referrer,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $orderId = (int) Db::name('orders')->insertGetId([
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
        $svc = new CommissionService();
        $svc->settleForOrder($orderId);
        $this->assertAuditHas('commission.settle', 'system');

        $svc->voidForOrder($orderId, 'order_refund');
        $this->assertAuditHas('commission.void_refund', 'system', '关联订单退款');

        // 5) commission.void_admin — a fresh (non-voided) record, because the
        // refund above made every record on the first order terminal.
        $orderId2 = (int) Db::name('orders')->insertGetId([
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
        $svc->settleForOrder($orderId2);
        $record = Db::name('commission_records')->where('order_id', $orderId2)->find();
        self::assertIsArray($record);
        $svc->voidByAdmin((int) $record['id'], $staff, '审计覆盖撤销用例');
        $this->assertAuditHas('commission.void_admin', 'admin');
    }

    private function assertAuditHas(string $action, string $actorType, ?string $reason = null): void
    {
        $q = Db::name('distribution_audit_log')
            ->where('action', $action)
            ->where('actor_type', $actorType);
        if ($reason !== null) {
            $q->where('reason', $reason);
        }
        self::assertGreaterThanOrEqual(
            1,
            (int) $q->count(),
            "write path {$action} (actor={$actorType}) must produce an audit row",
        );
    }

    /** @return array<string, mixed> */
    private function configInput(): array
    {
        return [
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
        ];
    }
}
