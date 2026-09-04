<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\PaymentConfigService;
use App\support\Crypto\AesGcmCipher;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class PaymentConfigServiceTest extends TestCase
{
    private const ENC_KEY = 'eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHh4eHg=';
    private int $staffId;

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
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'payment-config-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Payment Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        putenv('PAYMENT_KEY_ENC_KEY');
    }

    public function testAesRoundTrip(): void
    {
        $cipher = AesGcmCipher::encrypt('test_merchant_key_12345', self::ENC_KEY);
        self::assertStringStartsWith('v1:', $cipher);
        self::assertSame('test_merchant_key_12345', AesGcmCipher::decrypt($cipher, self::ENC_KEY));
    }

    public function testMissingEncryptionKeyFailsBeforeWriting(): void
    {
        putenv('PAYMENT_KEY_ENC_KEY=');
        $service = new PaymentConfigService();
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED');
        $service->update($this->staffId, $this->input('merchant-key'));
    }

    public function testUpdatePersistsCipherAndInvalidatesMaskedCache(): void
    {
        $service = new PaymentConfigService();
        $first = $service->update($this->staffId, $this->input('merchant-first'));
        self::assertSame(1, $first['version']);
        self::assertSame('********irst', $first['merchant_key_masked']);
        self::assertSame('merchant-first', $service->merchantKey());

        $row = Db::name('payment_config')->where('id', 1)->find();
        self::assertIsArray($row);
        self::assertStringStartsWith('v1:', (string) $row['merchant_key_cipher']);
        self::assertStringNotContainsString('merchant-first', (string) $row['merchant_key_cipher']);
        self::assertSame(1, (int) Db::name('audit_log')->where('action', 'payment_config.update')->count());

        $second = $service->update($this->staffId, $this->input('merchant-second'));
        self::assertSame(2, $second['version']);
        self::assertSame('********cond', $second['merchant_key_masked']);
        self::assertSame('merchant-second', $service->merchantKey());
        self::assertSame(2, (int) Db::name('audit_log')->where('action', 'payment_config.update')->count());
    }

    public function testStaleVersionIsRejected(): void
    {
        $service = new PaymentConfigService();
        $first = $service->update($this->staffId, $this->input('merchant-first'));
        $latest = $service->update($this->staffId, [
            ...$this->input('merchant-second'),
            'version' => $first['version'],
        ]);

        self::assertSame(2, $latest['version']);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('PAYMENT_CONFIG_VERSION_CONFLICT');
        $service->update($this->staffId, [
            ...$this->input('merchant-third'),
            'version' => $first['version'],
        ]);
    }

    public function testShortMerchantKeyIsRejectedBeforeDatabaseAccess(): void
    {
        $service = new PaymentConfigService();
        $input = $this->input('short');
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('INVALID_MERCHANT_KEY_VALUE');
        $service->update($this->staffId, $input);
    }

    /** @return array<string, mixed> */
    private function input(string $merchantKey): array
    {
        return [
            'enabled' => true,
            'api_url' => 'https://z-pay.cn/',
            'pid' => '20220726190052',
            'merchant_key' => $merchantKey,
            'notify_url' => 'https://learn.example.test/notify',
            'return_url' => 'https://learn.example.test/return',
            'enabled_channels' => ['wxpay', 'alipay'],
            'whitelist_only' => false,
        ];
    }
}
