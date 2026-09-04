<?php

declare(strict_types=1);

namespace Tests;

use App\service\PaymentConfigService;
use App\support\payment\NotifyResult;
use App\support\payment\ZPayPaymentAdapter;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ZPayPaymentAdapterTest extends TestCase
{
    private const KEY = 'adapter-secret';
    private const ENC_KEY = 'a2tra2tra2tra2tra2tra2tra2tra2tra2tra2tra2s=';

    private int $orderId;
    private ZPayPaymentAdapter $adapter;

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
        $staffId = $this->insertAccount('staff', 'zpay-staff-' . bin2hex(random_bytes(3)), $now);
        Db::name('staff_users')->insert([
            'account_id' => $staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'ZPay Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $learnerId = $this->insertAccount('learner', '139' . random_int(10000000, 99999999), $now);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'zpay-category-' . bin2hex(random_bytes(3)),
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
            'title' => 'ZPay 测试课程',
            'cover_url' => null,
            'teacher_name' => 'Tester',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 99,
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
            'list_price_snapshot' => 99,
            'sale_price_snapshot' => 0,
            'coupon_discount_snapshot' => 0,
            'paid_amount' => 99,
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
            'enabled_channels' => ['wxpay', 'alipay'],
            'whitelist_only' => false,
        ]);
        $this->adapter = new ZPayPaymentAdapter(new PaymentConfigService());
    }

    protected function tearDown(): void
    {
        Db::rollback();
        putenv('PAYMENT_KEY_ENC_KEY');
    }

    public function testSignUsesSortedNonEmptyFieldsAndKnownMd5Vector(): void
    {
        $method = (new \ReflectionClass($this->adapter))->getMethod('sign');
        $method->setAccessible(true);

        $actual = $method->invoke($this->adapter, [
            'b' => '2',
            'empty' => '',
            'sign' => 'ignored',
            'sign_type' => 'MD5',
            'a' => '1',
        ], 'secret');

        self::assertSame(md5('a=1&b=2secret'), $actual);
    }

    public function testCreateChargeReturnsOrderedRedirectParameters(): void
    {
        $charge = $this->adapter->createCharge($this->orderId, 99.0, 'CNY', 'wxpay');
        $queryString = parse_url((string) $charge['redirect_url'], PHP_URL_QUERY);
        parse_str((string) $queryString, $query);

        self::assertSame(
            ['pid', 'type', 'notify_url', 'return_url', 'out_trade_no', 'name', 'money', 'param', 'sign_type', 'sign'],
            array_keys($query),
        );
        self::assertSame('wxpay', $query['type']);
        self::assertSame((string) $this->orderId, $query['out_trade_no']);
        self::assertSame('99.00', $query['money']);
        self::assertSame('zpay', $charge['provider']);
        self::assertSame('wxpay', $charge['channel']);
    }

    public function testParseNotifyAcceptsGetAndPostAndMapsStatus(): void
    {
        $success = $this->notifyPayload('TRADE_SUCCESS', '99.00', 'trade-get');
        $get = $this->request('GET', $success);
        $parsedGet = $this->adapter->parseNotify($get);
        self::assertInstanceOf(NotifyResult::class, $parsedGet);
        self::assertSame('succeeded', $parsedGet->status);
        self::assertSame('trade-get', $parsedGet->providerRef);

        $closed = $this->notifyPayload('TRADE_CLOSED', '99.00', 'trade-post');
        $post = $this->request('POST', $closed);
        $parsedPost = $this->adapter->parseNotify($post);
        self::assertInstanceOf(NotifyResult::class, $parsedPost);
        self::assertSame('failed', $parsedPost->status);

        $failed = $this->adapter->parseNotify(
            $this->request('POST', $this->notifyPayload('TRADE_FAIL', '99.00', 'trade-fail')),
        );
        self::assertInstanceOf(NotifyResult::class, $failed);
        self::assertSame('failed', $failed->status);

        $other = $this->adapter->parseNotify(
            $this->request('POST', $this->notifyPayload('SOMETHING_ELSE', '99.00', 'trade-other')),
        );
        self::assertInstanceOf(NotifyResult::class, $other);
        self::assertSame('failed', $other->status);
    }

    public function testParseNotifyRejectsBadSignatureUnknownOrderAndMoneyMismatch(): void
    {
        $bad = $this->notifyPayload('TRADE_SUCCESS', '99.00', 'trade-bad');
        $bad['sign'] = str_repeat('0', 32);
        self::assertNull($this->adapter->parseNotify($this->request('POST', $bad)));

        $unknown = $this->notifyPayload('TRADE_SUCCESS', '99.00', 'trade-unknown');
        $unknown['out_trade_no'] = '999999999';
        $unknown['sign'] = $this->signPayload($unknown);
        self::assertNull($this->adapter->parseNotify($this->request('POST', $unknown)));

        $mismatch = $this->notifyPayload('TRADE_SUCCESS', '98.99', 'trade-mismatch');
        self::assertNull($this->adapter->parseNotify($this->request('POST', $mismatch)));
    }

    /** @return array<string, string> */
    private function notifyPayload(string $status, string $money, string $tradeNo): array
    {
        $payload = [
            'pid' => '20220726190052',
            'type' => 'wxpay',
            'out_trade_no' => (string) $this->orderId,
            'trade_no' => $tradeNo,
            'name' => 'ZPay 测试课程',
            'money' => $money,
            'param' => '',
            'trade_status' => $status,
            'sign_type' => 'MD5',
        ];
        $payload['sign'] = $this->signPayload($payload);
        return $payload;
    }

    /** @param array<string, string> $payload */
    private function signPayload(array $payload): string
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
        $contentType = $method === 'POST' ? 'application/x-www-form-urlencoded' : '';
        $uri = $method === 'GET'
            ? '/api/internal/v1/payments/zpay/notify?' . $body
            : '/api/internal/v1/payments/zpay/notify';
        return new Request(
            $method . ' ' . $uri . " HTTP/1.1\r\nHost: test\r\n"
                . ($contentType === '' ? '' : 'Content-Type: ' . $contentType . "\r\n")
                . "\r\n" . ($method === 'POST' ? $body : ''),
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
