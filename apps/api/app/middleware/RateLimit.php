<?php

declare(strict_types=1);

namespace App\middleware;

use App\support\ApiResponse;
use App\support\Logger;
use support\Redis;
use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

final class RateLimit implements MiddlewareInterface
{
    /** @var array<string, array{key: string, limit: int, window: int}> */
    private const RULES = [
        'POST /api/learner/v1/auth/login' => ['key' => 'learner_auth_login', 'limit' => 10, 'window' => 60],
        'POST /api/learner/v1/auth/register' => ['key' => 'learner_auth_register', 'limit' => 10, 'window' => 60],
        'POST /api/learner/v1/auth/refresh' => ['key' => 'learner_auth_refresh', 'limit' => 30, 'window' => 60],
        'POST /api/admin/v1/auth/login' => ['key' => 'admin_auth_login', 'limit' => 10, 'window' => 60],
        'POST /api/admin/v1/auth/refresh' => ['key' => 'admin_auth_refresh', 'limit' => 30, 'window' => 60],
        'GET /api/learner/v1/checkins/today' => ['key' => 'learner_checkin_today', 'limit' => 30, 'window' => 60],
        'POST /api/learner/v1/checkins' => ['key' => 'learner_checkin_create', 'limit' => 5, 'window' => 60],
    ];

    private const SCRIPT = <<<'LUA'
local current = redis.call('INCR', KEYS[1])
if current == 1 then
    redis.call('EXPIRE', KEYS[1], ARGV[1])
end
return {current, redis.call('TTL', KEYS[1])}
LUA;

    public function process(Request $request, callable $handler): Response
    {
        $rule = $this->rule($request);
        if ($rule === null) {
            return $handler($request);
        }

        $redis = $this->redis();
        if ($redis === null) {
            return $handler($request);
        }

        try {
            $result = $redis->eval(
                self::SCRIPT,
                1,
                $this->key($request, $rule['key']),
                (string) $rule['window'],
            );
            if (!is_array($result)) {
                throw new \RuntimeException('rate_limit_invalid_redis_result');
            }

            $current = (int) ($result[0] ?? 0);
            $retryAfter = max(1, (int) ($result[1] ?? $rule['window']));
        } catch (\Throwable $exception) {
            Logger::error('rate_limit.redis_failed', ['err' => $exception->getMessage()]);
            // ponytail: fail open when Redis is unavailable; auth and business rules remain authoritative.
            return $handler($request);
        }

        $remaining = max(0, $rule['limit'] - $current);
        $headers = [
            'X-RateLimit-Limit' => (string) $rule['limit'],
            'X-RateLimit-Remaining' => (string) $remaining,
            'Retry-After' => (string) $retryAfter,
        ];

        if ($current > $rule['limit']) {
            return ApiResponse::fail(
                ApiResponse::RATE_LIMITED,
                'RATE_LIMITED',
                $request->request_id ?? null,
            )->withHeaders($headers);
        }

        return $handler($request)->withHeaders($headers);
    }

    /** @return array{key: string, limit: int, window: int}|null */
    private function rule(Request $request): ?array
    {
        $signature = strtoupper($request->method()) . ' ' . $request->path();
        if (isset(self::RULES[$signature])) {
            return self::RULES[$signature];
        }

        if (
            $request->method() === 'POST'
            && preg_match('#^/api/learner/v1/coupons/[^/]+/claim$#', $request->path()) === 1
        ) {
            return ['key' => 'learner_coupon_claim', 'limit' => 10, 'window' => 60];
        }

        return null;
    }

    private function key(Request $request, string $ruleKey): string
    {
        $accountId = (int) ($request->account_id ?? 0);
        $scope = $accountId > 0 ? 'account:' . $accountId : 'ip:' . $request->getRealIp();
        return 'rate_limit:v1:' . hash('sha256', $scope . '|' . $ruleKey);
    }

    private function redis(): ?object
    {
        if (class_exists(\Tests\RedisStub::class) && \Tests\RedisStub::$instance !== null) {
            $stub = \Tests\RedisStub::$instance;
            if ($stub->stayDown || $stub->downOnNextCall) {
                $stub->downOnNextCall = false;
                return null;
            }
            return $stub;
        }

        try {
            return Redis::connection('default');
        } catch (\Throwable $exception) {
            Logger::error('rate_limit.redis_unavailable', ['err' => $exception->getMessage()]);
            return null;
        }
    }
}
