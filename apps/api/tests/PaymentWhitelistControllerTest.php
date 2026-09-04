<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\PaymentWhitelistController;
use App\service\PaymentWhitelistService;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Context;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class PaymentWhitelistControllerTest extends TestCase
{
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
            'login' => 'payment-whitelist-controller-' . bin2hex(random_bytes(4)),
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
            'display_name' => 'Whitelist Controller Test',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
        Context::reset();
    }

    public function testPostValidationReturnsBadRequest(): void
    {
        $request = $this->request('POST', ['phone' => 'bad'], $this->staffId);
        Context::reset(new ArrayObject([\Webman\Http\Request::class => $request]));
        $response = (new PaymentWhitelistController(new PaymentWhitelistService()))->create($request);
        $body = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INVALID_PHONE', $body['error']['message'] ?? null);
    }

    public function testPostRejectsNonBooleanEnabled(): void
    {
        $response = (new PaymentWhitelistController(new PaymentWhitelistService()))->create(
            $this->request('POST', [
                'phone' => '13800001234',
                'enabled' => 'false',
            ], $this->staffId),
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('INVALID_ENABLED', $this->body($response)['error']['message'] ?? null);
    }

    public function testCrudListAndSoftDelete(): void
    {
        $controller = new PaymentWhitelistController(new PaymentWhitelistService());
        $created = $controller->create($this->request('POST', [
            'phone' => '13800001234',
            'enabled' => true,
            'note' => '运营测试',
        ], $this->staffId));
        $createdBody = $this->body($created);
        $id = (int) ($createdBody['data']['id'] ?? 0);
        self::assertSame(201, $created->getStatusCode());
        self::assertSame('138****1234', $createdBody['data']['phone_masked'] ?? null);

        $updated = $controller->update(
            $this->request('PATCH', ['enabled' => false, 'note' => null], $this->staffId),
            (string) $id,
        );
        self::assertSame(200, $updated->getStatusCode());
        self::assertFalse($this->body($updated)['data']['enabled'] ?? true);

        $list = $controller->index($this->requestWithQuery('GET', '?page=1&limit=20', $this->staffId));
        self::assertSame(1, $this->body($list)['data']['total'] ?? null);

        $deleted = $controller->delete(
            $this->request('DELETE', [], $this->staffId),
            (string) $id,
        );
        self::assertSame(204, $deleted->getStatusCode());
        self::assertSame(0, (int) Db::name('payment_whitelist')->whereNull('deleted_at')->count());
    }

    public function testDeleteWithoutAccountIsUnauthorized(): void
    {
        $response = (new PaymentWhitelistController(new PaymentWhitelistService()))->delete(
            $this->request('DELETE', [], 0),
            '1',
        );

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('UNAUTHENTICATED', $this->body($response)['error']['code'] ?? null);
    }

    /** @param array<string,mixed> $body */
    private function request(string $method, array $body, int $accountId): Request
    {
        return $this->requestWithQuery($method, '', $accountId, $body);
    }

    /** @param array<string, mixed> $body */
    private function requestWithQuery(string $method, string $query, int $accountId, array $body = []): Request
    {
        $request = new Request(
            "$method /api/admin/v1/payment/whitelist$query HTTP/1.1\r\nHost: test\r\nContent-Type: application/json\r\n\r\n"
                . json_encode($body),
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $accountId;
        return $request;
    }

    /** @return array<string, mixed> */
    private function body(\support\Response $response): array
    {
        $body = json_decode((string) $response->rawBody(), true);
        self::assertIsArray($body);
        return $body;
    }
}
