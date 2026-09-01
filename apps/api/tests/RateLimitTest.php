<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\RateLimit;
use App\support\ApiResponse;
use PHPUnit\Framework\TestCase;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\Route;

final class RateLimitTest extends TestCase
{
    private InMemoryRedis $redis;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
    }

    public function testLearnerCheckinIsLimitedPerAccountAndWindow(): void
    {
        $limiter = new RateLimit();
        $handler = static fn (Request $request): Response => ApiResponse::ok(['handled' => true]);

        for ($i = 0; $i < 30; $i++) {
            $response = $limiter->process(
                $this->request('GET', '/api/learner/v1/checkins/today', 42),
                $handler,
            );
            self::assertSame(200, $response->getStatusCode());
        }

        $blocked = $limiter->process(
            $this->request('GET', '/api/learner/v1/checkins/today', 42),
            $handler,
        );
        $payload = json_decode((string) $blocked->rawBody(), true);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('RATE_LIMITED', $payload['error']['code'] ?? null);
        self::assertSame('30', $blocked->getHeader('X-RateLimit-Limit'));
        self::assertSame('0', $blocked->getHeader('X-RateLimit-Remaining'));
        self::assertSame('60', $blocked->getHeader('Retry-After'));

        self::assertSame(
            200,
            $limiter->process($this->request('GET', '/api/learner/v1/checkins/today', 43), $handler)
                ->getStatusCode(),
        );

        $this->redis->advanceClock(60);
        self::assertSame(
            200,
            $limiter->process($this->request('GET', '/api/learner/v1/checkins/today', 42), $handler)
                ->getStatusCode(),
        );
    }

    public function testRedisFailureDoesNotBlockTheBusinessRequest(): void
    {
        $this->redis->stayDown = true;
        $called = false;
        $response = (new RateLimit())->process(
            $this->request('POST', '/api/learner/v1/checkins', 42),
            static function (Request $request) use (&$called): Response {
                $called = true;
                return ApiResponse::ok(['handled' => true]);
            },
        );

        self::assertTrue($called);
        self::assertSame(200, $response->getStatusCode());
    }

    public function testBusinessHandlerIsNotRetriedWhenItThrows(): void
    {
        $calls = 0;
        try {
            (new RateLimit())->process(
                $this->request('GET', '/api/learner/v1/checkins/today', 42),
                static function (Request $request) use (&$calls): Response {
                    $calls++;
                    throw new \RuntimeException('business_failed');
                },
            );
            self::fail('Expected the business exception to propagate');
        } catch (\RuntimeException $exception) {
            self::assertSame('business_failed', $exception->getMessage());
        }

        self::assertSame(1, $calls);
    }

    public function testKeyAuthAndBusinessRoutesAreMountedWithRateLimit(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = $route->getMiddleware();
            }
        }

        foreach (
            [
                'POST /api/learner/v1/auth/login',
                'POST /api/learner/v1/auth/refresh',
                'GET /api/learner/v1/checkins/today',
                'POST /api/learner/v1/checkins',
                'POST /api/learner/v1/coupons/{campaignId}/claim',
                'POST /api/admin/v1/auth/login',
                'POST /api/admin/v1/auth/refresh',
            ] as $route
        ) {
            self::assertContains(RateLimit::class, $routes[$route] ?? [], $route);
        }
    }

    private function request(string $method, string $path, int $accountId): Request
    {
        $request = new Request("$method $path HTTP/1.1\r\nHost: test\r\n\r\n");
        /** @phpstan-ignore-next-line */
        $request->account_id = $accountId;
        $request->request_id = 'rate-limit-test';
        return $request;
    }
}
