<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\StaffController;
use App\middleware\AdminAuth;
use App\middleware\Authorize;
use App\service\PermissionService;
use App\service\PermissionOverrideService;
use App\service\StaffService;
use App\service\TokenService;
use App\support\ApiResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\Http\Response;
use Webman\Route;
use Webman\ThinkOrm\ThinkOrm;

final class StaffOverrideApiTest extends TestCase
{
    private int $actorId;
    private int $targetId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        /** @phpstan-ignore-next-line */
        Db::startTrans();
        $suffix = bin2hex(random_bytes(4));
        $this->actorId = $this->insertStaffAccount("t081-actor-$suffix", true);
        $this->targetId = $this->insertStaffAccount("t081-target-$suffix", false);
    }

    protected function tearDown(): void
    {
        /** @phpstan-ignore-next-line */
        Db::rollback();
    }

    public function testOverrideRouteIsPutOnlyAndProtectedByAdminMiddleware(): void
    {
        $routes = $this->routes();
        $key = 'PUT /api/admin/v1/staff/{id}/overrides';

        self::assertArrayHasKey($key, $routes);
        self::assertSame(
            [StaffController::class, 'overrides'],
            $routes[$key]['callback'],
        );
        self::assertContains(AdminAuth::class, $routes[$key]['middleware']);
        self::assertContains(Authorize::class, $routes[$key]['middleware']);
        self::assertSame('org.grant', Authorize::permissionFor('/api/admin/v1/staff/12/overrides', 'PUT'));

        $samePath = array_values(array_filter(
            array_keys($routes),
            static fn(string $route): bool => str_contains($route, ' /api/admin/v1/staff/{id}/overrides'),
        ));
        self::assertSame([$key], $samePath);
    }

    public function testControllerReturnsCanonicalEnvelopeAndDelegatesEntries(): void
    {
        $entries = [
            ['code' => 'qa.answer', 'effect' => 'grant'],
            ['code' => 'order.view', 'effect' => 'deny'],
        ];
        $request = $this->jsonRequest(
            'PUT /api/admin/v1/staff/' . $this->targetId . '/overrides',
            ['entries' => $entries],
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->actorId;
        $response = $this->controller()->overrides($request, (string) $this->targetId);

        self::assertSame(200, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertTrue($body['ok']);
        self::assertSame(['qa.answer', 'order.view'], array_column($body['data']['overrides'], 'code'));
        self::assertSame(['grant', 'deny'], array_column($body['data']['overrides'], 'effect'));
    }

    #[DataProvider('invalidBodyProvider')]
    public function testControllerRejectsMalformedOrMissingEntries(string $body, string $message): void
    {
        $request = new Request(
            "PUT /api/admin/v1/staff/12/overrides HTTP/1.1\r\nHost: test\r\n\r\n" . $body,
        );
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->actorId;
        $response = $this->controller()->overrides($request, (string) $this->targetId);

        self::assertSame(400, $response->getStatusCode());
        self::assertSame([
            'ok' => false,
            'data' => null,
            'error' => ['code' => ApiResponse::VALIDATION_FAILED, 'message' => $message],
        ], $this->decode($response));
    }

    /** @return iterable<string, array{string,string}> */
    public static function invalidBodyProvider(): iterable
    {
        yield 'empty body' => ['', 'OVERRIDE_ENTRIES_REQUIRED'];
        yield 'invalid json' => ['{"entries":', 'INVALID_JSON'];
        yield 'missing entries' => ['{}', 'OVERRIDE_ENTRIES_REQUIRED'];
        yield 'entries is not an array' => ['{"entries":null}', 'OVERRIDE_ENTRIES_REQUIRED'];
    }

    public function testMissingOrgGrantIsRejectedWithoutCallingHandler(): void
    {
        $request = new Request("PUT /api/admin/v1/staff/12/overrides HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->permissions = ['org.staff'];
        /** @phpstan-ignore-next-line */
        $request->request_id = 'req-t081';
        $called = false;

        $response = (new Authorize())->process($request, static function () use (&$called): Response {
            $called = true;
            return ApiResponse::ok(['unexpected' => true]);
        });

        self::assertFalse($called);
        self::assertSame(403, $response->getStatusCode());
        self::assertSame([
            'ok' => false,
            'data' => null,
            'error' => ['code' => ApiResponse::FORBIDDEN, 'message' => 'FORBIDDEN'],
            'meta' => ['request_id' => 'req-t081'],
        ], $this->decode($response));
    }

    public function testControllerMapsServiceBusinessErrorToCanonicalEnvelope(): void
    {
        $request = $this->jsonRequest('PUT /api/admin/v1/staff/' . $this->actorId . '/overrides', [
            'entries' => [['code' => 'qa.answer', 'effect' => 'grant']],
        ]);
        /** @phpstan-ignore-next-line */
        $request->account_id = $this->actorId;
        $response = $this->controller()->overrides($request, (string) $this->actorId);

        self::assertSame(403, $response->getStatusCode());
        self::assertSame([
            'ok' => false,
            'data' => null,
            'error' => ['code' => ApiResponse::FORBIDDEN, 'message' => 'SELF_GUARD'],
        ], $this->decode($response));
    }

    private function controller(): StaffController
    {
        $permissions = new PermissionService();
        return new StaffController(
            new StaffService($permissions, new TokenService(), new PermissionOverrideService($permissions)),
            $permissions,
            new TokenService(),
        );
    }

    private function insertStaffAccount(string $login, bool $superAdmin): int
    {
        $now = '2026-08-26 00:00:00';
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $login,
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $accountId,
            'is_super_admin' => $superAdmin ? 1 : 0,
            'department_id' => null,
            'display_name' => "T081 $accountId",
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    /** @return array<string, array{callback:mixed,middleware:list<mixed>}> */
    private function routes(): array
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = [
                    'callback' => $route->getCallback(),
                    'middleware' => $route->getMiddleware(),
                ];
            }
        }
        return $routes;
    }

    /** @param array<string,mixed> $body */
    private function jsonRequest(string $path, array $body): Request
    {
        return new Request(
            "PUT $path HTTP/1.1\r\nHost: test\r\nContent-Type: application/json\r\n\r\n"
            . json_encode($body, JSON_THROW_ON_ERROR),
        );
    }

    /** @return array<string,mixed> */
    private function decode(Response $response): array
    {
        $body = json_decode((string) $response->rawBody(), true);
        self::assertIsArray($body);
        return $body;
    }
}
