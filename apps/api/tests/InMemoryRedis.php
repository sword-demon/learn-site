<?php
declare(strict_types=1);

namespace Tests;

/**
 * Tiny Redis replacement for unit tests. Implements the slice of the phpredis
 * surface that TokenService + CaptchaService depend on.
 *
 * Important: a real \Redis instance has dozens of methods; we only stub what
 * the services actually call. Keep this list narrow — adding methods means
 * we test a richer surface, which is fine but not required for FR-091/092.
 */
final class InMemoryRedis
{
    /** @var array<string, string> */
    private array $store = [];
    /** @var array<string, int> */
    private array $expires = [];
    private int $clock = 0;
    public bool $downOnNextCall = false;
    public bool $stayDown = false;

    public function get(string $key): string|false
    {
        $this->maybeFail();
        if ($this->isExpired($key)) {
            unset($this->store[$key], $this->expires[$key]);
            return false;
        }
        $v = $this->store[$key] ?? false;
        return $v === false ? false : (string) $v;
    }

    public function set(string $key, string $value, string|int|null $expOption = null, int|string|null $ttl = null): bool
    {
        $this->maybeFail();
        $this->store[$key] = $value;
        if ($expOption !== null) {
            $seconds = (int) ($expOption === 'EX' ? (int) $ttl : $expOption);
            $this->expires[$key] = $this->clock + $seconds;
        }
        return true;
    }

    public function setex(string $key, int $ttl, string $value): bool
    {
        $this->maybeFail();
        $this->store[$key] = $value;
        $this->expires[$key] = $this->clock + $ttl;
        return true;
    }

    public function del(string ...$keys): int
    {
        $this->maybeFail();
        $deleted = 0;
        foreach ($keys as $k) {
            if (isset($this->store[$k])) {
                unset($this->store[$k], $this->expires[$k]);
                $deleted++;
            }
        }
        return $deleted;
    }

    public function exists(string $key): int
    {
        $this->maybeFail();
        return (isset($this->store[$key]) && !$this->isExpired($key)) ? 1 : 0;
    }

    public function ttl(string $key): int
    {
        $this->maybeFail();
        if (!isset($this->store[$key])) {
            return -2;
        }
        $rem = ($this->expires[$key] ?? 0) - $this->clock;
        return $rem > 0 ? $rem : -2;
    }

    public function scan(?int &$iterator, array $options = []): array|false
    {
        $this->maybeFail();
        $match = (string) ($options['match'] ?? $options['MATCH'] ?? '*');
        $quoted = preg_quote($match, '/');
        $regex = '/^' . str_replace(['\\*', '\\?'], ['.*', '.'], $quoted) . '$/';
        $keys = [];
        foreach (array_keys($this->store) as $key) {
            if ($this->isExpired($key)) {
                continue;
            }
            if (preg_match($regex, $key)) {
                $keys[] = $key;
            }
        }
        $iterator = 0;
        // Illuminate's PhpRedisConnection returns [next cursor, keys].
        return [$iterator, $keys];
    }

    /** Test-only: read the underlying captcha answer so the test can submit it. */
    public function peekCaptcha(string $id): string
    {
        $v = $this->store['captcha:' . $id] ?? '';
        return $v === '' ? '' : strtolower(trim(preg_replace('/\s+/', '', $v) ?? ''));
    }

    public function advanceClock(int $seconds): void
    {
        $this->clock += $seconds;
    }

    private function maybeFail(): void
    {
        if ($this->stayDown || $this->downOnNextCall) {
            $this->downOnNextCall = false;
            throw new \RuntimeException('redis_down_for_test');
        }
    }

    private function isExpired(string $key): bool
    {
        if (!isset($this->expires[$key])) {
            return false;
        }
        return $this->clock >= $this->expires[$key];
    }
}
