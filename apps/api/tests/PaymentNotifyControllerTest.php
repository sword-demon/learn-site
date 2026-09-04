<?php

declare(strict_types=1);

namespace Tests;

use App\controller\internal\PaymentNotifyController;
use App\service\EntitlementService;
use App\service\OrderService;
use App\service\PaymentConfigService;
use App\support\payment\ZPayPaymentAdapter;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class PaymentNotifyControllerTest extends TestCase
{
    private const KEY = 'controller-secret';
    private const ENC_KEY = 'Y2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2NjY2M=';

    private int $orderId;
    private ZPayPaymentAdapter $adapter;
    private PaymentNotifyController $controller;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        putenv('PAYMENT_KEY_ENC_KEY=' . self::ENC_KEY);
        putenv('PAYMENT_NOTIFY_ASYNC=0');
        Db::startTrans();
        $now = date('Y-m-d H:i:s');
        $staffId = $this->insertAccount('staff', 'notify-staff-' . bin2hex(random_bytes(3)), $now);
        Db::name('staff_users')->insert([
            'account_id' => $staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Notify Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $learnerId = $this->insertAccount('learner', '138' . random_int(10000000, 99999999), $now);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'notify-category-' . bin2hex(random_bytes(3)),
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
            'title' => 'Notify 课程',
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
        $this->orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'learner_coupon_id' => null,
            'list_price_snapshot' => 50,
            'sale_price_snapshot' => 0,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 50,
            'currency' => 'CNY',
            'status' => 'pending',
            'provider' => 'zpay',
            'provider_ref' => null,
            'succeeded_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (new PaymentConfigService())->update($staffId, [
            'enabled' => true,
            'api_url' => 'https://z-pay.test/',
            'pid' => '20220726190052',
            'merchant_key' => self::KEY,
            'notify_url' => 'https://learn.example.test/api/internal/v1/payments/zpay/notify',
            'return_url' => 'https://learn.example.test/orders/result',
            'enabled_channels' => ['wxpay'],
            'whitelist_only' => false,
        ]);
        $this->adapter = new ZPayPaymentAdapter(new PaymentConfigService());
        $this->controller = new PaymentNotifyController(
            new OrderService(new EntitlementService(), $this->adapter),
            $this->adapter,
        );
    }

    protected function tearDown(): void
    {
        Db::rollback();
        putenv('PAYMENT_KEY_ENC_KEY');
        putenv('PAYMENT_NOTIFY_ASYNC');
        putenv('QUEUE_SYNC');
    }

    public function testValidNotifySettlesOrderAndReplayIsIdempotent(): void
    {
        $request = $this->request('POST', $this->payload('TRADE_SUCCESS', '50.00', 'trade-1'));
        $first = $this->controller->zpayNotify($request);
        $second = $this->controller->zpayNotify($request);

        self::assertSame(200, $first->getStatusCode());
        self::assertSame(200, $second->getStatusCode());
        self::assertSame('succeeded', Db::name('orders')->where('id', $this->orderId)->value('status'));
        self::assertSame('trade-1', Db::name('orders')->where('id', $this->orderId)->value('provider_ref'));
        self::assertSame(1, (int) Db::name('course_entitlements')->where('order_id', $this->orderId)->count());
        self::assertSame(1, (int) Db::name('audit_log')->where('action', 'zpay.notify.succeeded')->count());
    }

    public function testAsyncNotifySettlesAndAuditsAfterQueueConsumption(): void
    {
        putenv('PAYMENT_NOTIFY_ASYNC=1');
        putenv('QUEUE_SYNC=1');

        $response = $this->controller->zpayNotify(
            $this->request('POST', $this->payload('TRADE_SUCCESS', '50.00', 'trade-async')),
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('succeeded', Db::name('orders')->where('id', $this->orderId)->value('status'));
        self::assertSame(1, (int) Db::name('audit_log')->where('action', 'zpay.notify.succeeded')->count());
    }

    public function testUnknownOrderAndMoneyMismatchAreAcknowledgedAndAudited(): void
    {
        $unknown = $this->payload('TRADE_SUCCESS', '50.00', 'trade-unknown');
        $unknown['out_trade_no'] = '999999999';
        $unknown['sign'] = $this->sign($unknown);
        $unknownResponse = $this->controller->zpayNotify($this->request('POST', $unknown));

        $mismatch = $this->payload('TRADE_SUCCESS', '49.99', 'trade-mismatch');
        $mismatchResponse = $this->controller->zpayNotify($this->request('POST', $mismatch));

        self::assertSame(200, $unknownResponse->getStatusCode());
        self::assertSame(200, $mismatchResponse->getStatusCode());
        self::assertSame('pending', Db::name('orders')->where('id', $this->orderId)->value('status'));
        self::assertGreaterThanOrEqual(1, (int) Db::name('audit_log')->where('action', 'zpay.notify.unknown_order')->count());
        self::assertGreaterThanOrEqual(1, (int) Db::name('audit_log')->where('action', 'zpay.notify.amount_mismatch')->count());
    }

    public function testInvalidNotifySignatureReturnsBadRequestAndIsAudited(): void
    {
        $payload = $this->payload('TRADE_SUCCESS', '50.00', 'trade-invalid');
        $payload['sign'] = str_repeat('0', 32);

        $response = $this->controller->zpayNotify($this->request('POST', $payload));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(1, (int) Db::name('audit_log')->where('action', 'zpay.notify.invalid_signature')->count());
    }

    public function testReturnInvalidSignatureRedirectsAndValidReturnDoesNotSettle(): void
    {
        $invalid = $this->payload('TRADE_SUCCESS', '50.00', 'trade-return');
        $invalid['sign'] = str_repeat('0', 32);
        $invalidResponse = $this->controller->zpayReturn($this->request('GET', $invalid));

        $validResponse = $this->controller->zpayReturn(
            $this->request('GET', $this->payload('TRADE_SUCCESS', '50.00', 'trade-return')),
        );

        self::assertSame(302, $invalidResponse->getStatusCode());
        self::assertSame('http://localhost:8080/orders?status=invalid', $invalidResponse->getHeader('Location'));
        self::assertSame(302, $validResponse->getStatusCode());
        self::assertSame(
            'http://localhost:8080/orders/' . $this->orderId . '?status=pending&trade_no=trade-return',
            $validResponse->getHeader('Location'),
        );
        self::assertSame('pending', Db::name('orders')->where('id', $this->orderId)->value('status'));
    }

    /** @return array<string, string> */
    private function payload(string $status, string $money, string $tradeNo): array
    {
        $payload = [
            'pid' => '20220726190052',
            'type' => 'wxpay',
            'out_trade_no' => (string) $this->orderId,
            'trade_no' => $tradeNo,
            'name' => 'Notify 课程',
            'money' => $money,
            'param' => '',
            'trade_status' => $status,
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->sign($payload);
        return $payload;
    }

    /** @param array<string, string> $payload */
    private function sign(array $payload): string
    {
        unset($payload['sign'], $payload['sign_type']);
        ksort($payload);
        $parts = [];
        foreach ($payload as $key => $value) {
            if ($value !== '') {
                $parts[] = $key . '=' . stripslashes($value);
            }
        }
        return strtolower(md5(implode('&', $parts) . self::KEY));
    }

    /** @param array<string, string> $payload */
    private function request(string $method, array $payload): Request
    {
        $body = http_build_query($payload, '', '&', PHP_QUERY_RFC3986);
        if ($method === 'GET') {
            return new Request(
                'GET /api/internal/v1/payments/zpay/return?' . $body . " HTTP/1.1\r\nHost: test\r\n\r\n",
            );
        }
        return new Request(
            "POST /api/internal/v1/payments/zpay/notify HTTP/1.1\r\nHost: test\r\n"
                . "Content-Type: application/x-www-form-urlencoded\r\n\r\n" . $body,
        );
    }

    private function insertAccount(string $kind, string $login, string $now): int
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
