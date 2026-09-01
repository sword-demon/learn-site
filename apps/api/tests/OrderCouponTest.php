<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CouponService;
use App\service\EntitlementService;
use App\service\OrderService;
use App\support\payment\FakePaymentAdapter;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * OrderCouponTest — 009-learner-coupons.
 *
 * Verifies the OrderService ↔ CouponService handshake:
 *  - createPending accepts an optional learner_coupon_id and stamps the
 *     immutable discount snapshot on the order row.
 *  - markSucceeded calls redeemOnSuccess, transitioning the coupon to
 *     `used` and incrementing coupon_campaigns.used_count.
 *  - markFailed releases the lock so the coupon returns to `unused`.
 *  - pending-order reuse rejects a different coupon id with
 *     ORDER_PENDING_COUPON_MISMATCH.
 *  - Re-delivereded payment callbacks (markSucceeded twice) do not
 *     double-count used_count.
 */
final class OrderCouponTest extends TestCase
{
    private OrderService $orders;
    private CouponService $coupons;
    private FakePaymentAdapter $payment;
    private int $staffId;
    private int $learnerId;
    private int $departmentId = 0;
    private int $courseId;

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
            'login' => 'oc-admin-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'OC Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->departmentId = (int) Db::name('departments')->insertGetId([
            'name' => 'oc-qa-' . bin2hex(random_bytes(3)),
            'parent_id' => 0,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'oc-cat-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $this->departmentId,
            'category_id' => $categoryId,
            'title' => 'OC Course',
            'cover_url' => null,
            'teacher_name' => 'Tester',
            'summary' => 'Order coupon test',
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 100.0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->coupons = new CouponService();
        $this->payment = new FakePaymentAdapter();
        $this->orders = new OrderService(
            new EntitlementService(),
            $this->payment,
            $this->coupons,
        );
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testCreatePendingWithCouponStampsDiscountSnapshot(): void
    {
        $campaignId = $this->seedCampaign(['min_amount' => 0, 'discount_amount' => 10]);
        $coupon = $this->coupons->claimByLearner($campaignId, $this->learnerId);

        $res = $this->orders->createPending($this->learnerId, $this->courseId, $coupon['id']);
        self::assertSame('pending', $res['status']);
        self::assertSame(10.0, $res['coupon_discount_snapshot']);
        self::assertSame(90.0, $res['paid_amount']);
        self::assertSame($coupon['id'], $res['learner_coupon_id']);

        $row = Db::name('orders')->where('id', $res['order_id'])->find();
        self::assertSame(10.0, (float) $row['coupon_discount_snapshot']);
        self::assertSame(90.0, (float) $row['paid_amount']);

        $couponRow = Db::name('learner_coupons')->where('id', $coupon['id'])->find();
        self::assertSame('locked', $couponRow['status']);
        self::assertSame($res['order_id'], (int) $couponRow['locked_order_id']);
    }

    public function testCreatePendingRejectsPendingOrderCouponMismatch(): void
    {
        $a = $this->seedCampaign(['name' => 'A']);
        $b = $this->seedCampaign(['name' => 'B']);
        $couponA = $this->coupons->claimByLearner($a, $this->learnerId);
        $couponB = $this->coupons->claimByLearner($b, $this->learnerId);

        $first = $this->orders->createPending($this->learnerId, $this->courseId, $couponA['id']);
        self::assertSame($couponA['id'], $first['learner_coupon_id']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ORDER_PENDING_COUPON_MISMATCH');
        $this->orders->createPending($this->learnerId, $this->courseId, $couponB['id']);
    }

    public function testMarkSucceededRedeemsCouponAndCountsOnce(): void
    {
        $campaignId = $this->seedCampaign();
        $coupon = $this->coupons->claimByLearner($campaignId, $this->learnerId);
        $res = $this->orders->createPending($this->learnerId, $this->courseId, $coupon['id']);

        $this->orders->markSucceeded($res['order_id'], 'fake-ref');
        $this->orders->markSucceeded($res['order_id'], 'fake-ref'); // idempotent re-delivery

        $usedCount = (int) Db::name('coupon_campaigns')->where('id', $campaignId)->value('used_count');
        self::assertSame(1, $usedCount);

        $couponRow = Db::name('learner_coupons')->where('id', $coupon['id'])->find();
        self::assertSame('used', $couponRow['status']);
        self::assertSame($res['order_id'], (int) $couponRow['used_order_id']);
    }

    public function testMarkFailedReleasesCoupon(): void
    {
        $campaignId = $this->seedCampaign();
        $coupon = $this->coupons->claimByLearner($campaignId, $this->learnerId);
        $res = $this->orders->createPending($this->learnerId, $this->courseId, $coupon['id']);
        $this->orders->markFailed($res['order_id'], 'failed');

        $couponRow = Db::name('learner_coupons')->where('id', $coupon['id'])->find();
        self::assertSame('unused', $couponRow['status']);
        self::assertNull($couponRow['locked_order_id']);
    }

    public function testCreatePendingWithoutCouponLeavesSnapshotZero(): void
    {
        $this->seedCampaign();
        $res = $this->orders->createPending($this->learnerId, $this->courseId, null);
        self::assertSame(0.0, $res['coupon_discount_snapshot']);
        self::assertSame(100.0, $res['paid_amount']);
        self::assertNull($res['learner_coupon_id']);
    }

    /** @param array<string, mixed> $overrides */
    private function seedCampaign(array $overrides = []): int {
        $payload = array_merge([
            'name' => 'OC coupon',
            'scope_type' => 'all',
            'min_amount' => 0,
            'discount_amount' => 10,
            'claim_mode' => 'public',
            'claim_starts_at' => isoFromOffsetOc(-1),
            'claim_ends_at' => isoFromOffsetOc(30),
            'use_ends_at' => null,
            'total_quota' => 100,
            'per_learner_claim_limit' => 5,
            'per_learner_use_limit' => 5,
        ], $overrides);
        $created = $this->coupons->createCampaign($payload, $this->staffId);
        return $created['id'];
    }
}

if (!function_exists('isoFromOffsetOc')) {
    function isoFromOffsetOc(int $days): string {
        return (new \DateTimeImmutable('now', new \DateTimeZone('Asia/Shanghai')))
            ->modify(($days >= 0 ? '+' : '') . $days . ' days')
            ->format(DATE_ATOM);
    }
}