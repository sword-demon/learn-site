<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\PaymentConfigController;
use App\service\PaymentConfigService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class PaymentConfigControllerTest extends TestCase
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
            'login' => 'payment-controller-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Payment Controller Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        putenv('PAYMENT_KEY_ENC_KEY');
    }

    public function testGetReturnsMaskedOnly(): void
    {
        $service = new PaymentConfigService();
        $service->update($this->staffId, $this->input('controller-secret'));

        $response = (new PaymentConfigController($service))->get($this->request('GET', []));
        $body = $this->body($response);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('********cret', $body['data']['merchant_key_masked'] ?? null);
        self::assertArrayNotHasKey('merchant_key', $body['data'] ?? []);
        self::assertStringNotContainsString('controller-secret', json_encode($body));
    }

    public function testPatchRejectsInvalidInput(): void
    {
        $input = $this->input('controller-secret');
        $input['api_url'] = 'not-a-url';
        $response = (new PaymentConfigController(new PaymentConfigService()))
            ->update($this->request('PATCH', $input));
        $body = $this->body($response);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('VALIDATION_FAILED', $body['error']['code'] ?? null);
        self::assertSame('INVALID_URL:api_url', $body['error']['message'] ?? null);
    }

    public function testPatchRequiresHttpsApiUrlAndValidPid(): void
    {
        $input = $this->input('controller-secret');
        $input['api_url'] = 'http://z-pay.cn/';
        $response = (new PaymentConfigController(new PaymentConfigService()))
            ->update($this->request('PATCH', $input));
        self::assertSame('INVALID_URL:api_url', $this->body($response)['error']['message'] ?? null);

        $input = $this->input('controller-secret');
        $input['pid'] = 'short';
        $response = (new PaymentConfigController(new PaymentConfigService()))
            ->update($this->request('PATCH', $input));
        self::assertSame('INVALID_PID', $this->body($response)['error']['message'] ?? null);
    }

    public function testPatchSuccessReturnsMaskAndStoresCipher(): void
    {
        $response = (new PaymentConfigController(new PaymentConfigService()))
            ->update($this->request('PATCH', $this->input('controller-secret')));
        $body = $this->body($response);
        $cipher = Db::name('payment_config')->where('id', 1)->value('merchant_key_cipher');

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('********cret', $body['data']['merchant_key_masked'] ?? null);
        self::assertSame(1, $body['data']['version'] ?? null);
        self::assertIsString($cipher);
        self::assertStringStartsWith('v1:', $cipher);
        self::assertStringNotContainsString('controller-secret', $cipher);
    }

    public function testPatchRejectsStaleVersion(): void
    {
        $controller = new PaymentConfigController(new PaymentConfigService());
        $first = $this->body($controller->update($this->request('PATCH', $this->input('controller-first'))));
        $latest = $controller->update($this->request('PATCH', [
            ...$this->input('controller-second'),
            'version' => $first['data']['version'],
        ]));

        self::assertSame(200, $latest->getStatusCode());
        $stale = $controller->update($this->request('PATCH', [
            ...$this->input('controller-third'),
            'version' => $first['data']['version'],
        ]));

        self::assertSame(409, $stale->getStatusCode());
        self::assertSame('PAYMENT_CONFIG_VERSION_CONFLICT', $this->body($stale)['error']['message'] ?? null);
    }

    public function testMissingEnvKeyReturnsInternalError(): void
    {
        putenv('PAYMENT_KEY_ENC_KEY=');
        $response = (new PaymentConfigController(new PaymentConfigService()))
            ->update($this->request('PATCH', $this->input('controller-secret')));
        $body = $this->body($response);

        self::assertSame(500, $response->getStatusCode());
        self::assertSame('INTERNAL', $body['error']['code'] ?? null);
        self::assertSame('PAYMENT_KEY_ENC_KEY_NOT_CONFIGURED', $body['error']['message'] ?? null);
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body): Request
    {
        $raw = $body === [] ? '' : json_encode($body, JSON_THROW_ON_ERROR);
        $request = new Request(
            $method . " /api/admin/v1/payment/config HTTP/1.1\r\n"
                . "Host: test\r\nContent-Type: application/json\r\n\r\n" . $raw,
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->staffId;
        return $request;
    }

    /** @return array<string, mixed> */
    private function body(\support\Response $response): array
    {
        $body = json_decode((string) $response->rawBody(), true);
        self::assertIsArray($body);
        return $body;
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
