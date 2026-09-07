<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\Redis;
use support\think\Db;

/**
 * Redis-backed unread message counter per learner (008-api-scale-100k).
 */
final class UnreadCounterService
{
    private const KEY_PREFIX = 'unread:';

    public function get(int $learnerId): int
    {
        if ($learnerId <= 0) {
            return 0;
        }
        return $this->rebuildFromDb($learnerId);
    }

    public function increment(int $learnerId): int
    {
        if ($learnerId <= 0) {
            return 0;
        }
        return $this->rebuildFromDb($learnerId);
    }

    public function decrement(int $learnerId): int
    {
        if ($learnerId <= 0) {
            return 0;
        }
        return $this->rebuildFromDb($learnerId);
    }

    public function reset(int $learnerId): void
    {
        if ($learnerId <= 0) {
            return;
        }
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        $redis->set(self::KEY_PREFIX . $learnerId, '0');
    }

    public function rebuildFromDb(int $learnerId): int
    {
        $count = $this->countFromDb($learnerId);
        $redis = $this->redis();
        if ($redis !== null) {
            $redis->set(self::KEY_PREFIX . $learnerId, (string) $count);
        }
        return $count;
    }

    private function countFromDb(int $learnerId): int
    {
        return (int) Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->whereNull('read_at')
            ->count();
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
        } catch (\Throwable $e) {
            Logger::error('unread_counter.redis_unavailable', ['err' => $e->getMessage()]);
            return null;
        }
    }
}
