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
 * SC-009 — CSV export carries masked phones only; no 11-digit plaintext
 * number may appear anywhere in the payload.
 */
final class ExportCsvMaskingTest extends TestCase
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

    public function testCsvContainsOnlyMaskedPhones(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'csv-' . bin2hex(random_bytes(4)),
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
        $referrerPhone = '138' . random_int(10000000, 99999999);
        $referrer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => $referrerPhone,
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $referrer, 'created_at' => $now, 'updated_at' => $now]);
        $buyer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(10000000, 99999999),
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
            'name' => 'csv-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'CSV Course',
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

        $csv = (new CommissionService())->exportCsv([]);
        $lines = explode("\n", trim($csv));
        self::assertSame(
            'id,order_id,course_id,referrer_masked_phone,level,amount_cents,status',
            $lines[0],
        );
        self::assertGreaterThan(1, count($lines));

        $expectedMasked = '138****' . substr($referrerPhone, -4);
        self::assertStringContainsString($expectedMasked, $csv);

        // No plaintext 11-digit phone anywhere in the export (SC-009).
        self::assertSame(0, (int) preg_match_all('/1[3-9]\d{9}/', $csv));
        self::assertStringNotContainsString($referrerPhone, $csv);
    }
}
