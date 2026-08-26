<?php
declare(strict_types=1);

namespace App\controller;

use App\support\ApiResponse;
use support\Redis;
use support\think\Db;

/**
 * GET /health — MySQL via think-orm, Redis via webman/redis.
 */
final class HealthController
{
    public function health(\support\Request $request): \support\Response
    {
        $checks = [
            'mysql' => $this->checkMysql(),
            'redis' => $this->checkRedis(),
        ];
        $ok = $checks['mysql'] === true && $checks['redis'] === true;
        return $ok
            ? ApiResponse::ok(['status' => 'ok', 'checks' => $checks])
            : ApiResponse::fail(ApiResponse::INTERNAL, 'unhealthy', $request->request_id ?? null);
    }

    private function checkMysql(): bool|string
    {
        try {
            $row = Db::query('SELECT 1 AS ok');
            if (!is_array($row) || $row === []) {
                return 'mysql_down';
            }
            return true;
        } catch (\Throwable $e) {
            return 'mysql_down';
        }
    }

    private function checkRedis(): bool|string
    {
        try {
            $reply = Redis::ping();
            return $reply === '+PONG' || $reply === true || $reply === 'PONG' ? true : 'redis_down';
        } catch (\Throwable $e) {
            return 'redis_down';
        }
    }
}
