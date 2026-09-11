<?php

declare(strict_types=1);

namespace Tests;

use App\service\CommissionService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class OrderRefundVoidCommissionTest extends TestCase
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

    public function testVoidForOrderMarksAllVoided(): void
    {
        $now = date('Y-m-d H:i:s');
        $referrer = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner', 'login' => '138' . random_int(10000000, 99999999),
            'password_hash' => 'x', 'must_change_password' => 0, 'status' => 'active',
            'created_at' => $now, 'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $referrer, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = 900001;
        Db::name('orders')->insert([
            'id' => $orderId,
            'learner_id' => $referrer,
            'course_id' => (int) (Db::name('courses')->value('id') ?: 0),
            'list_price_snapshot' => 10,
            'sale_price_snapshot' => 10,
            'paid_amount' => 10,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ((int) Db::name('orders')->where('id', $orderId)->count() === 0) {
            self::markTestSkipped('no course fixture');
        }
        Db::name('commission_records')->insert([
            'order_id' => $orderId,
            'course_id' => (int) Db::name('orders')->where('id', $orderId)->value('course_id'),
            'referee_learner_id' => $referrer,
            'referrer_learner_id' => $referrer,
            'level' => 1,
            'amount_cents' => 100,
            'status' => 'pending',
            'source' => 'system_settle',
            'config_snapshot_json' => '{}',
            'order_paid_cents_snapshot' => 1000,
            'created_at' => $now,
        ]);
        (new CommissionService())->voidForOrder($orderId, 'order_refund');
        $row = Db::name('commission_records')->where('order_id', $orderId)->find();
        self::assertSame('voided', $row['status']);
        self::assertSame('system_refund_void', $row['source']);
    }
}
