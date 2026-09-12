<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CommissionService;
use App\service\DistributionConfigService;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Disabling distribution freezes history: pending records stay untouched,
 * new share entries are refused, and re-enabling restores creation.
 */
final class DistributionDisableFlowTest extends TestCase
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

    public function testDisableFreezesPendingRecordsAndGatesCreation(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'dis-flow-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $config = new DistributionConfigService();
        $config->updateConfig($staff, $this->input(true));

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
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'dis-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Disable Flow Course',
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
        (new CommissionService())->settleForOrder($orderId);
        $before = Db::name('commission_records')->where('order_id', $orderId)->find();
        self::assertIsArray($before);
        self::assertSame('pending', (string) $before['status']);

        // Close the site-wide switch.
        $config->updateConfig($staff, $this->input(false));

        // Existing commission rows are never rewritten by the switch.
        $after = Db::name('commission_records')->where('order_id', $orderId)->find();
        self::assertIsArray($after);
        self::assertSame('pending', (string) $after['status']);
        self::assertSame((int) $before['amount_cents'], (int) $after['amount_cents']);

        // Learner-side creation is gated.
        try {
            (new ShareEntryService())->create($referrer, 'site', null);
            self::fail('share entry creation must be refused while distribution is disabled');
        } catch (BusinessException $e) {
            self::assertSame('DISTRIBUTION_DISABLED', $e->getMessage());
            self::assertSame('FORBIDDEN', $e->apiCode);
        }
        self::assertSame(0, (int) Db::name('share_entries')->where('learner_id', $referrer)->count());
    }

    public function testReEnableRestoresCreation(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'dis-re-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $config = new DistributionConfigService();
        $config->updateConfig($staff, $this->input(false));
        $learner = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '16' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $learner, 'created_at' => $now, 'updated_at' => $now]);

        $config->updateConfig($staff, $this->input(true));
        $entry = (new ShareEntryService())->create($learner, 'site', null);
        self::assertNotEmpty($entry['plaintext_code']);
    }

    /** @return array<string, mixed> */
    private function input(bool $enabled): array
    {
        return [
            'enabled' => $enabled,
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
