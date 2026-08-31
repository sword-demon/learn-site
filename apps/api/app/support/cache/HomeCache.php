<?php

declare(strict_types=1);

namespace App\support\cache;

use support\Redis;

/**
 * TTL cache for public home aggregates (008-api-scale-100k).
 */
final class HomeCache
{
    private const PREFIX = 'cache:home:';

    public const KEY_SITE_INTRO = 'site_intro';
    public const KEY_CATEGORY_TREE = 'category_tree';
    public const KEY_BANNERS = 'banners';

    public function remember(string $key, int $ttlSeconds, callable $producer): mixed
    {
        if ($this->shouldSkipCache()) {
            return $producer();
        }

        $fullKey = self::PREFIX . $key;
        $redis = $this->redis();
        if ($redis !== null) {
            $cached = $redis->get($fullKey);
            if ($cached !== false && $cached !== null && $cached !== '') {
                $decoded = json_decode((string) $cached, true);
                if (is_array($decoded) || $decoded === []) {
                    return $decoded;
                }
            }
        }

        $value = $producer();
        if ($redis !== null && $ttlSeconds > 0) {
            $redis->setex(
                $fullKey,
                $ttlSeconds,
                json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        }
        return $value;
    }

    public function forget(string $key): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        $redis->del(self::PREFIX . $key);
    }

    public function forgetAll(): void
    {
        foreach ([self::KEY_SITE_INTRO, self::KEY_CATEGORY_TREE, self::KEY_BANNERS] as $key) {
            $this->forget($key);
        }
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
        } catch (\Throwable) {
            return null;
        }
    }

    public static function ttlSiteIntro(): int
    {
        return max(1, (int) (getenv('HOME_CACHE_TTL_SITE') ?: 300));
    }

    public static function ttlCategoryTree(): int
    {
        return max(1, (int) (getenv('HOME_CACHE_TTL_CATEGORY') ?: 300));
    }

    public static function ttlBanners(): int
    {
        return max(1, (int) (getenv('HOME_CACHE_TTL_BANNERS') ?: 60));
    }

    private function shouldSkipCache(): bool
    {
        $flag = getenv('HOME_CACHE_ENABLED');
        if ($flag !== false && $flag !== '') {
            return !filter_var($flag, FILTER_VALIDATE_BOOLEAN);
        }
        return getenv('APP_ENV') === 'testing';
    }
}
