<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\PaymentWhitelistService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class PaymentWhitelistServiceTest extends TestCase
{
    private PaymentWhitelistService $service;
    private int $staffId;

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
            'login' => 'payment-whitelist-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Whitelist Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->service = new PaymentWhitelistService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testInvalidPhoneIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('INVALID_PHONE');
        $this->service->add($this->staffId, '123', true, null);
    }

    public function testDuplicatePhoneIsRejected(): void
    {
        $this->service->add($this->staffId, '13800001234', true, null);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('WHITELIST_DUPLICATE');
        $this->service->add($this->staffId, '13800001234', true, null);
    }

    public function testToggleDeleteAndLookupIgnoreDisabledAndDeletedRows(): void
    {
        $id = $this->service->add($this->staffId, '13800001234', true, 'test');
        self::assertTrue($this->service->isWhitelisted('13800001234'));

        $this->service->toggle($this->staffId, $id, false);
        self::assertFalse($this->service->isWhitelisted('13800001234'));
        $this->service->toggle($this->staffId, $id, true);
        self::assertTrue($this->service->isWhitelisted('13800001234'));

        $this->service->softDelete($this->staffId, $id);
        self::assertFalse($this->service->isWhitelisted('13800001234'));
        self::assertNotNull(Db::name('payment_whitelist')->where('id', $id)->value('deleted_at'));
        self::assertSame(4, (int) Db::name('audit_log')
            ->where('target_type', 'payment_whitelist')
            ->where('target_id', $id)
            ->count());
    }

    public function testListPaginatesAndOmitsSoftDeletedRows(): void
    {
        $first = $this->service->add($this->staffId, '13800001234', true, null);
        $this->service->add($this->staffId, '13900001234', true, null);
        $this->service->softDelete($this->staffId, $first);

        $result = $this->service->list(1, 20);

        self::assertSame(1, $result['total']);
        self::assertCount(1, $result['items']);
        self::assertSame('139****1234', $result['items'][0]['phone_masked']);
    }
}
