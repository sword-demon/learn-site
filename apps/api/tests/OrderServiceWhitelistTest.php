<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\EntitlementService;
use App\service\OrderService;
use App\service\PaymentConfigService;
use App\service\PaymentWhitelistService;
use App\support\payment\FakePaymentAdapter;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class OrderServiceWhitelistTest extends TestCase
{
    private const ENC_KEY = 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=';

    private int $staffId;
    private int $learnerId;
    private int $courseId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        putenv('PAYMENT_KEY_ENC_KEY=' . self::ENC_KEY);
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $this->staffId = $this->account('staff', 'whitelist-order-staff-' . bin2hex(random_bytes(3)), $now);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Whitelist Order Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = $this->account('learner', '138' . random_int(10000000, 99999999), $now);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'whitelist-order-category-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => 'Whitelist Order Course',
            'cover_url' => null,
            'teacher_name' => 'Tester',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 50,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        putenv('PAYMENT_KEY_ENC_KEY');
    }

    public function testWhitelistDisabledSkipsPhoneCheck(): void
    {
        $orders = $this->orders(false);

        $result = $orders->createPending($this->learnerId, $this->courseId, null, 'wxpay');

        self::assertSame('pending', $result['status']);
    }

    public function testUnlistedLearnerIsRejectedWhenWhitelistOnly(): void
    {
        $orders = $this->orders(true);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('NOT_IN_WHITELIST');
        $orders->createPending($this->learnerId, $this->courseId);
    }

    public function testEnabledListedLearnerCanCreateOrder(): void
    {
        $this->orders(true);
        (new PaymentWhitelistService())->add($this->staffId, $this->learnerPhone(), true, null);
        $orders = $this->orders(true);

        $result = $orders->createPending($this->learnerId, $this->courseId, null, 'alipay');

        self::assertSame('pending', $result['status']);
    }

    public function testDisabledListedLearnerIsRejected(): void
    {
        $this->orders(true);
        $id = (new PaymentWhitelistService())->add($this->staffId, $this->learnerPhone(), false, null);
        $orders = $this->orders(true);

        self::assertFalse((bool) Db::name('payment_whitelist')->where('id', $id)->value('enabled'));
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('NOT_IN_WHITELIST');
        $orders->createPending($this->learnerId, $this->courseId);
    }

    private function orders(bool $whitelistOnly): OrderService
    {
        $config = new PaymentConfigService();
        $config->update($this->staffId, [
            'enabled' => true,
            'api_url' => 'https://z-pay.cn/',
            'pid' => '20220726190052',
            'merchant_key' => 'whitelist-secret',
            'notify_url' => 'https://learn.example.test/notify',
            'return_url' => 'https://learn.example.test/return',
            'enabled_channels' => ['wxpay', 'alipay'],
            'whitelist_only' => $whitelistOnly,
        ]);
        return new OrderService(
            new EntitlementService(),
            new FakePaymentAdapter(),
            null,
            $config,
            new PaymentWhitelistService(),
        );
    }

    private function learnerPhone(): string
    {
        return (string) Db::name('accounts')->where('id', $this->learnerId)->value('login');
    }

    private function account(string $kind, string $login, string $now): int
    {
        return (int) Db::name('accounts')->insertGetId([
            'kind' => $kind,
            'login' => $login,
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
