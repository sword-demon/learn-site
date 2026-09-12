<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CommissionService;
use App\service\DistributionConfigService;
use App\service\DistributionCourseOverrideService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class DistributionCourseOverrideServiceTest extends TestCase
{
    private int $staffId;
    private DistributionCourseOverrideService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'dist-ovr-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->service = new DistributionCourseOverrideService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    private function course(): int
    {
        $now = date('Y-m-d H:i:s');
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'ovr-cat-' . bin2hex(random_bytes(2)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => 'Override Course ' . bin2hex(random_bytes(2)),
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
    }

    private function learner(int $referrer): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'referrer_learner_id' => $referrer,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    public function testUpsertCreatesThenUpdatesWithAudit(): void
    {
        $courseId = $this->course();
        $created = $this->service->upsert($courseId, ['enabled' => true, 'level1_pct' => 0.30], $this->staffId);
        self::assertTrue($created['enabled']);
        self::assertSame(0.30, (float) $created['level1_pct']);
        self::assertSame(1, (int) Db::name('distribution_course_overrides')->where('course_id', $courseId)->count());
        self::assertSame(
            1,
            (int) Db::name('distribution_audit_log')
                ->where('action', 'course.override.update')
                ->where('subject_type', 'course_override')
                ->where('subject_id', $courseId)
                ->count(),
        );

        $updated = $this->service->upsert($courseId, ['enabled' => false], $this->staffId);
        self::assertFalse($updated['enabled']);
        // Update must not create a second row.
        self::assertSame(1, (int) Db::name('distribution_course_overrides')->where('course_id', $courseId)->count());
        self::assertSame(2, (int) Db::name('distribution_audit_log')->where('action', 'course.override.update')->count());
    }

    public function testUpsertMissingCourseIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('COURSE_NOT_FOUND');
        $this->service->upsert(99999999, ['enabled' => true], $this->staffId);
    }

    public function testListReturnsItemsWithCourseName(): void
    {
        $courseId = $this->course();
        $this->service->upsert($courseId, ['enabled' => true], $this->staffId);
        $list = $this->service->list(1, 20);
        $hit = null;
        foreach ($list['items'] as $item) {
            if ((int) $item['course_id'] === $courseId) {
                $hit = $item;
            }
        }
        self::assertNotNull($hit);
        self::assertArrayHasKey('course_name', $hit);
        self::assertNotSame('Deleted Course', $hit['course_name']);
    }

    public function testOverrideOffSuppressesCommissionAndOnUsesCourseRatio(): void
    {
        $referrer = $this->buyer();
        $buyer = $this->learnerWithReferrer($referrer);
        [$noOverrideCourse, $overrideOn, $overrideOff] = [$this->course(), $this->course(), $this->course()];
        $this->enableGlobal();

        // Course-level ON with its own ratio: 30% instead of the global 10%.
        $this->service->upsert($overrideOn, ['enabled' => true, 'level1_pct' => 0.30], $this->staffId);
        $this->service->upsert($overrideOff, ['enabled' => false], $this->staffId);

        $orderIdOn = $this->succeededOrder($buyer, 1000.00, $overrideOn);
        (new CommissionService())->settleForOrder($orderIdOn);
        $rowOn = Db::name('commission_records')->where('order_id', $orderIdOn)->find();
        self::assertIsArray($rowOn);
        // Course ratio replaces the global one — no stacking (10% + 30% would be 40000).
        self::assertSame(30000, (int) $rowOn['amount_cents']);

        $orderIdOff = $this->succeededOrder($buyer, 1000.00, $overrideOff);
        (new CommissionService())->settleForOrder($orderIdOff);
        self::assertSame(0, (int) Db::name('commission_records')->where('order_id', $orderIdOff)->count());

        // Global config still applies to a course without an override.
        $orderIdGlobal = $this->succeededOrder($buyer, 1000.00, $noOverrideCourse);
        (new CommissionService())->settleForOrder($orderIdGlobal);
        $rowGlobal = Db::name('commission_records')->where('order_id', $orderIdGlobal)->find();
        self::assertIsArray($rowGlobal);
        self::assertSame(10000, (int) $rowGlobal['amount_cents']);
    }

    private function buyer(): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '15' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $id, 'created_at' => $now, 'updated_at' => $now]);
        return $id;
    }

    private function learnerWithReferrer(int $referrer): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '17' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'referrer_learner_id' => $referrer,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function enableGlobal(): void
    {
        (new DistributionConfigService())->updateConfig($this->staffId, [
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

    private function succeededOrder(int $learnerId, float $paid, int $courseId): int
    {
        $now = date('Y-m-d H:i:s');
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
