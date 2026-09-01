<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CouponService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * CouponTest — 009-learner-coupons.
 *
 * Exercises the CouponService surface end-to-end against the live MySQL
 * test container (transactionally rolled back per test):
 *   - create / patch / disable lifecycle
 *   - claim quota, per-learner limit, claim window
 *   - admin grant batch + skip-on-limit
 *   - state machine transitions (unused → locked → used, failed release)
 *   - audit_log rows for create / disable / grant
 *
 * Coverage focus: research R3 / R4 (lock + quota) and data-model.md
 * invariants (idempotent markSucceeded).
 */
final class CouponTest extends TestCase
{
    private CouponService $service;
    private int $staffId = 0;
    private int $departmentId = 0;
    private int $categoryId = 0;
    private int $courseId = 0;

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
            'login' => 'coupon-admin-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Coupon Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->departmentId = (int) Db::name('departments')->insertGetId([
            'name' => 'coupon-qa-' . bin2hex(random_bytes(3)),
            'parent_id' => 0,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'qa-cat-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $this->departmentId,
            'category_id' => $this->categoryId,
            'title' => 'QA Course',
            'cover_url' => null,
            'teacher_name' => 'Tester',
            'summary' => 'Coupon test course',
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 100.00,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->service = new CouponService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    // ---------- create ----------

    public function testCreateRejectsDiscountExceedingMin(): void
    {
        $this->expectException(BusinessException::class);
        $this->service->createCampaign([
            'name' => 'Bad Rule',
            'scope_type' => 'all',
            'min_amount' => 50,
            'discount_amount' => 60,
            'claim_mode' => 'public',
            'claim_starts_at' => isoFromOffset(-1),
            'claim_ends_at' => isoFromOffset(30),
            'use_ends_at' => null,
            'total_quota' => 100,
            'per_learner_claim_limit' => 1,
            'per_learner_use_limit' => 1,
        ], $this->staffId);
    }

    public function testCreateStoresAndAudits(): void
    {
        $created = $this->service->createCampaign([
            'name' => 'QA 满 50 减 10',
            'scope_type' => 'all',
            'min_amount' => 0,
            'discount_amount' => 10,
            'claim_mode' => 'public',
            'claim_starts_at' => isoFromOffset(-1),
            'claim_ends_at' => isoFromOffset(30),
            'use_ends_at' => null,
            'total_quota' => 100,
            'per_learner_claim_limit' => 1,
            'per_learner_use_limit' => 1,
        ], $this->staffId);
        self::assertSame(1, (int) Db::name('audit_log')
            ->where('action', 'coupon.create')
            ->where('target_id', $created['id'])
            ->count());
        self::assertSame('active', $created['status']);
    }

    public function testCreateRequiresScopeJunctionForCategoryScope(): void
    {
        $this->expectException(BusinessException::class);
        $this->service->createCampaign([
            'name' => 'No categories',
            'scope_type' => 'category',
            'scope_category_ids' => [],
            'scope_course_ids' => [],
            'min_amount' => 0,
            'discount_amount' => 10,
            'claim_mode' => 'public',
            'claim_starts_at' => isoFromOffset(-1),
            'claim_ends_at' => isoFromOffset(30),
            'use_ends_at' => null,
            'total_quota' => 10,
            'per_learner_claim_limit' => 1,
            'per_learner_use_limit' => 1,
        ], $this->staffId);
    }

    // ---------- disable ----------

    public function testDisableCascadesUnusedAndLockedInstancesToVoided(): void
    {
        $campaignId = $this->seedCampaign(['total_quota' => 5]);
        $learnerId = $this->seedLearner();
        $this->service->grantToLearners($campaignId, ['learner_ids' => [$learnerId]], $this->staffId);
        $this->service->disableCampaign($campaignId, $this->staffId);
        $row = Db::name('learner_coupons')
            ->where('campaign_id', $campaignId)
            ->where('learner_id', $learnerId)
            ->find();
        self::assertSame('voided', $row['status']);
        self::assertSame(1, (int) Db::name('audit_log')
            ->where('action', 'coupon.disable')
            ->where('target_id', $campaignId)
            ->count());
    }

    // ---------- claim ----------

    public function testClaimRespectsQuotaAndLimit(): void
    {
        $campaignId = $this->seedCampaign(['total_quota' => 1]);
        $a = $this->seedLearner();
        $b = $this->seedLearner();
        $this->service->claimByLearner($campaignId, $a);
        $this->expectException(BusinessException::class);
        $this->service->claimByLearner($campaignId, $b);
    }

    public function testAdminGrantAndLearnerClaimShareQuota(): void
    {
        $campaignId = $this->seedCampaign(['total_quota' => 1]);
        $grantedLearner = $this->seedLearner();
        $claimingLearner = $this->seedLearner();

        $result = $this->service->grantToLearners(
            $campaignId,
            ['learner_ids' => [$grantedLearner]],
            $this->staffId,
        );

        self::assertSame(1, $result['granted']);
        $this->expectExceptionMessage('COUPON_QUOTA_EXCEEDED');
        $this->service->claimByLearner($campaignId, $claimingLearner);
    }

    public function testClaimRejectsOutsideWindow(): void {
        $campaignId = $this->seedCampaign([
            'claim_starts_at' => isoFromOffset(5),
            'claim_ends_at' => isoFromOffset(10),
        ]);
        $learnerId = $this->seedLearner();
        $this->expectException(BusinessException::class);
        $this->service->claimByLearner($campaignId, $learnerId);
    }

    public function testClaimMatchesListClaimableWhenSystemTimezoneIsUtc(): void
    {
        $previous = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            $learnerId = $this->seedLearner();
            $shanghai = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai'));
            $campaignId = $this->seedCampaign([
                'claim_starts_at' => $shanghai->modify('-2 hours')->format(DATE_ATOM),
                'claim_ends_at' => $shanghai->modify('+30 days')->format(DATE_ATOM),
            ]);
            $claimable = $this->service->listClaimable($learnerId);
            self::assertNotEmpty(
                array_filter($claimable, static fn (array $row): bool => (int) $row['id'] === $campaignId),
            );
            $coupon = $this->service->claimByLearner($campaignId, $learnerId);
            self::assertSame($campaignId, $coupon['campaign_id']);
        } finally {
            date_default_timezone_set($previous);
        }
    }

    public function testAdminGrantSkipsAtLimit(): void {
        $campaignId = $this->seedCampaign([
            'per_learner_claim_limit' => 1,
            'claim_mode' => 'admin_only',
        ]);
        $a = $this->seedLearner();
        $b = $this->seedLearner();
        $first = $this->service->grantToLearners($campaignId, ['learner_ids' => [$a]], $this->staffId);
        $second = $this->service->grantToLearners($campaignId, ['learner_ids' => [$a, $b]], $this->staffId);
        self::assertSame(1, $first['granted']);
        self::assertSame(1, $second['granted']);
        self::assertSame(1, $second['skipped']);
    }

    // ---------- order-time lock / redeem / release ----------

    public function testLockForOrderTransitionsToLockedAndStampsDiscount(): void {
        $campaignId = $this->seedCampaign([
            'min_amount' => 50,
            'discount_amount' => 10,
        ]);
        $learnerId = $this->seedLearner();
        $coupon = $this->service->claimByLearner($campaignId, $learnerId);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'list_price_snapshot' => 100.0,
            'sale_price_snapshot' => 0.0,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 100.0,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => 'fake',
            'provider_ref' => null,
            'succeeded_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $lock = $this->service->lockForOrder($learnerId, $this->courseId, $coupon['id'], $orderId);
        self::assertSame(10.0, $lock['coupon_discount']);
        $row = Db::name('learner_coupons')->where('id', $coupon['id'])->find();
        self::assertSame('locked', $row['status']);
        self::assertSame($orderId, (int) $row['locked_order_id']);
    }

    public function testRedeemOnSuccessIsIdempotent(): void {
        $campaignId = $this->seedCampaign();
        $learnerId = $this->seedLearner();
        $coupon = $this->service->claimByLearner($campaignId, $learnerId);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'list_price_snapshot' => 100.0,
            'sale_price_snapshot' => 0.0,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 100.0,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => 'fake',
            'provider_ref' => null,
            'succeeded_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->service->lockForOrder($learnerId, $this->courseId, $coupon['id'], $orderId);
        $this->service->redeemOnSuccess($orderId);
        $this->service->redeemOnSuccess($orderId); // double-call must not double-count
        $usedCount = (int) Db::name('coupon_campaigns')->where('id', $campaignId)->value('used_count');
        self::assertSame(1, $usedCount);
    }

    public function testReleaseOnTerminalReturnsToUnused(): void {
        $campaignId = $this->seedCampaign();
        $learnerId = $this->seedLearner();
        $coupon = $this->service->claimByLearner($campaignId, $learnerId);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $this->courseId,
            'list_price_snapshot' => 100.0,
            'sale_price_snapshot' => 0.0,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 100.0,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => 'fake',
            'provider_ref' => null,
            'succeeded_at' => null,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        $this->service->lockForOrder($learnerId, $this->courseId, $coupon['id'], $orderId);
        $this->service->releaseOnTerminal($orderId);
        $row = Db::name('learner_coupons')->where('id', $coupon['id'])->find();
        self::assertSame('unused', $row['status']);
        self::assertNull($row['locked_order_id']);
    }

    public function testListCheckoutOptionsReturnsEligibleAndIneligible(): void {
        $this->seedCampaign();
        $campaignId = $this->seedCampaign([
            'min_amount' => 50,
            'discount_amount' => 10,
            'scope_type' => 'all',
        ]);
        $learnerId = $this->seedLearner();
        $claimed = $this->service->claimByLearner($campaignId, $learnerId);
        $res = $this->service->listCheckoutOptions($learnerId, $this->courseId);
        self::assertSame(100.0, $res['base_price']);
        self::assertCount(1, $res['items']);
        self::assertNotSame($campaignId, $claimed['id']);
        self::assertSame($claimed['id'], $res['items'][0]['id']);
        self::assertTrue($res['items'][0]['eligible']);
        self::assertSame(90.0, $res['items'][0]['payable_preview']);
    }

    // ---------- helpers ----------

    /** @param array<string, mixed> $overrides */
    private function seedCampaign(array $overrides = []): int {
        $payload = array_merge([
            'name' => 'QA coupon',
            'scope_type' => 'all',
            'min_amount' => 0,
            'discount_amount' => 10,
            'claim_mode' => 'public',
            'claim_starts_at' => isoFromOffset(-1),
            'claim_ends_at' => isoFromOffset(30),
            'use_ends_at' => null,
            'total_quota' => 10,
            'per_learner_claim_limit' => 1,
            'per_learner_use_limit' => 1,
        ], $overrides);
        $created = $this->service->createCampaign($payload, $this->staffId);
        return $created['id'];
    }

    private function seedLearner(): int {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }
}

if (!function_exists('isoFromOffset')) {
    function isoFromOffset(int $days): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format(DATE_ATOM);
    }
}
