<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CommissionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Admin void rules: reason ≥ 5 chars, voided is terminal, and an order that
 * somehow carries more than 3 receiver rows is hard-rejected.
 */
final class AdminVoidRequiresReasonTest extends TestCase
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

    public function testShortReasonIsRejected(): void
    {
        $recordId = $this->settleOne();
        foreach (['', 'abc', '四个字啊', '  ab  '] as $reason) {
            try {
                (new CommissionService())->voidByAdmin($recordId, 1, $reason);
                self::fail("reason '{$reason}' must be rejected");
            } catch (BusinessException $e) {
                self::assertStringContainsString('VOID_REASON_TOO_SHORT', $e->getMessage());
            }
        }
        $row = Db::name('commission_records')->where('id', $recordId)->find();
        self::assertIsArray($row);
        self::assertSame('pending', (string) $row['status']);
    }

    public function testVoidWithValidReasonIsTerminal(): void
    {
        $recordId = $this->settleOne();
        $svc = new CommissionService();
        $voided = $svc->voidByAdmin($recordId, 1, '违规推广撤销');
        self::assertSame('voided', (string) $voided['status']);

        $row = Db::name('commission_records')->where('id', $recordId)->find();
        self::assertIsArray($row);
        self::assertSame('voided', (string) $row['status']);
        self::assertSame('admin_void', (string) $row['source']);
        self::assertSame('违规推广撤销', (string) $row['void_reason']);

        // Terminal: voiding again is rejected, and window settle must not revive it.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('COMMISSION_ALREADY_VOIDED');
        $svc->voidByAdmin($recordId, 1, '再次撤销无效');
    }

    public function testVoidWritesAudit(): void
    {
        $recordId = $this->settleOne();
        (new CommissionService())->voidByAdmin($recordId, 1, '审计覆盖用例');
        self::assertGreaterThanOrEqual(
            1,
            (int) Db::name('distribution_audit_log')
                ->where('action', 'commission.void_admin')
                ->where('actor_type', 'admin')
                ->where('subject_id', $recordId)
                ->count(),
        );
    }

    public function testOrderWithMoreThanThreeReceiversIsHardRejected(): void
    {
        $now = date('Y-m-d H:i:s');
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'void4-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Void Four Receivers',
            'cover_url' => null,
            'teacher_name' => 'T',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 10,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $buyer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '19' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $buyer, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $buyer,
            'course_id' => $courseId,
            'list_price_snapshot' => 10,
            'sale_price_snapshot' => 10,
            'paid_amount' => 10,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'succeeded_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        for ($i = 1; $i <= 4; $i++) {
            // DB CHECK caps level at 3, so the injected 4th receiver row
            // reuses level 3 with a distinct referrer id.
            Db::name('commission_records')->insert([
                'order_id' => $orderId,
                'course_id' => $courseId,
                'referee_learner_id' => $buyer,
                'referrer_learner_id' => $buyer + $i,
                'level' => min($i, 3),
                'amount_cents' => 100,
                'status' => 'pending',
                'source' => 'system_settle',
                'config_snapshot_json' => '{}',
                'order_paid_cents_snapshot' => 1000,
                'created_at' => $now,
            ]);
        }
        $victim = Db::name('commission_records')
            ->where('order_id', $orderId)
            ->order('level', 'asc')
            ->find();
        self::assertIsArray($victim);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('数据完整性硬约束');
        (new CommissionService())->voidByAdmin((int) $victim['id'], 1, '四人接收异常撤销');
    }

    /**
     * @return int commission_records.id of a settled level-1 record
     */
    private function settleOne(): int
    {
        $now = date('Y-m-d H:i:s');
        $buyer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '17' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $referrer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '18' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $referrer,
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
            'name' => 'void-cat-' . bin2hex(random_bytes(2)),
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
            'title' => 'Void Course',
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
        $record = Db::name('commission_records')->where('order_id', $orderId)->find();
        self::assertIsArray($record);
        return (int) $record['id'];
    }
}
