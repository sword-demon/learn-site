<?php
declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\Redis;

/**
 * TokenService — opaque access + refresh tokens (FR-092).
 *
 *   access:{hash}  →  { account_id, kind, family_id, exp }   TTL 15 min
 *   refresh:{hash} →  { account_id, kind, family_id, exp }   TTL 7 days
 *   family:{id}    →  revoked_at (set on kick or reuse-detect)
 *
 * Rules:
 *  - tokens are 32-byte URL-safe random strings; the raw value is NEVER
 *    persisted, only its SHA-256 hash.
 *  - refresh always rotates: the old refresh is deleted and a new pair
 *    issued with the same family_id.
 *  - refresh reuse detection revokes the whole family (FR-092).
 *  - kick(account) revokes every family; kick(account, family_id) revokes
 *    just that one family.
 *  - Redis unreachable → fail closed (login + protected requests reject).
 *  - No session, no account lockout, no IP throttle (out of MVP scope).
 */
final class TokenService
{
    public const KIND_LEARNER = 'learner';
    public const KIND_STAFF   = 'staff';

    private const ACCESS_PREFIX  = 'access:';
    private const REFRESH_PREFIX = 'refresh:';
    private const FAMILY_PREFIX  = 'family:';
    private const SPENT_PREFIX   = 'spent:';

    public function __construct(
        private readonly int $accessTtl  = 900,
        private readonly int $refreshTtl = 604800,
    ) {}

    /** Mint a new (access, refresh) pair bound to a fresh login family. */
    public function issue(string $accountId, string $kind): ?array
    {
        try {
            $familyId = self::randomId();
            $access = $this->mint(self::ACCESS_PREFIX, $accountId, $kind, $familyId, $this->accessTtl);
            $refresh = $this->mint(self::REFRESH_PREFIX, $accountId, $kind, $familyId, $this->refreshTtl);
            return [
                'access_token' => $access,
                'access_expires_in' => $this->accessTtl,
                'refresh_token' => $refresh,
                'refresh_expires_in' => $this->refreshTtl,
                'family_id' => $familyId,
            ];
        } catch (\RuntimeException $e) {
            Logger::error('token.issue.failed', ['err' => $e->getMessage()]);
            return null;
        }
    }

    /** Rotate a refresh token. Reuse → revoke family, return null. */
    public function rotate(string $refreshToken, ?string $expectedKind = null): ?array
    {
        $hash = self::hash($refreshToken);
        $key = self::REFRESH_PREFIX . $hash;
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }
        $payload = $redis->get($key);
        if (!$payload) {
            $spentFamily = $redis->get(self::SPENT_PREFIX . $hash);
            if (is_string($spentFamily) && $spentFamily !== '') {
                $this->revokeFamily($spentFamily);
                Logger::warning('refresh.reuse_detected', ['family_id' => $spentFamily]);
            }
            return null;
        }
        $row = json_decode((string) $payload, true) ?: [];
        $familyId = (string) ($row['family_id'] ?? '');
        $accountId = (string) ($row['account_id'] ?? '');
        $kind = (string) ($row['kind'] ?? '');
        if ($familyId === '' || $accountId === '' || $kind === '') {
            return null;
        }
        if ($expectedKind !== null && $kind !== $expectedKind) {
            return null;
        }
        if ($redis->exists(self::FAMILY_PREFIX . $familyId . ':revoked')) {
            return null;
        }

        $currentHash = $redis->get(self::FAMILY_PREFIX . $familyId);
        if (is_string($currentHash) && $currentHash !== '' && $currentHash !== $hash) {
            $this->revokeFamily($familyId);
            Logger::warning('refresh.reuse_detected', [
                'account_id' => $accountId, 'kind' => $kind, 'family_id' => $familyId,
            ]);
            return null;
        }

        $redis->setex(self::SPENT_PREFIX . $hash, $this->refreshTtl, $familyId);
        $redis->del($key);

        // Mint a new pair on the same family (refresh rotation).
        $newAccess = $this->mint(self::ACCESS_PREFIX, $accountId, $kind, $familyId, $this->accessTtl);
        $newRefresh = $this->mint(self::REFRESH_PREFIX, $accountId, $kind, $familyId, $this->refreshTtl);

        return [
            'access_token'        => $newAccess,
            'access_expires_in'   => $this->accessTtl,
            'refresh_token'       => $newRefresh,
            'refresh_expires_in'  => $this->refreshTtl,
            'family_id'           => $familyId,
            'account_id'          => $accountId,
            'kind'                => $kind,
        ];
    }

    /**
     * Verify an access token. Returns [account_id, kind, family_id] on success.
     * Returns null when missing, expired, revoked, or Redis is down.
     */
    public function verifyAccess(string $accessToken): ?array
    {
        $redis = $this->redis();
        if ($redis === null) {
            return null;
        }
        $payload = $redis->get(self::ACCESS_PREFIX . self::hash($accessToken));
        if (!$payload) {
            return null;
        }
        $row       = json_decode((string) $payload, true) ?: [];
        $familyId  = (string) ($row['family_id'] ?? '');
        $accountId = (string) ($row['account_id'] ?? '');
        $kind      = (string) ($row['kind'] ?? '');
        if ($familyId === '' || $accountId === '' || $kind === '') {
            return null;
        }
        // If the family is revoked, the access is too.
        if ($redis->exists(self::FAMILY_PREFIX . $familyId . ':revoked')) {
            return null;
        }
        return ['account_id' => $accountId, 'kind' => $kind, 'family_id' => $familyId];
    }

    /** Kick all families of an account (kick-all). */
    public function kickAll(string $accountId): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }
        $count = 0;
        foreach (['access:', 'refresh:'] as $prefix) {
            foreach ($this->scanKeys($redis, $prefix . '*') as $key) {
                $payload = $redis->get($key);
                if (!$payload) {
                    continue;
                }
                $row = json_decode((string) $payload, true) ?: [];
                if ((string) ($row['account_id'] ?? '') === $accountId) {
                    $redis->del($key);
                    $count++;
                    $familyId = (string) ($row['family_id'] ?? '');
                    if ($familyId !== '') {
                        $redis->set(self::FAMILY_PREFIX . $familyId . ':revoked', '1', 'EX', $this->refreshTtl);
                    }
                }
            }
        }
        return $count;
    }

    /** Kick one family (kick-one-family). */
    public function kickFamily(string $accountId, string $familyId): int
    {
        $redis = $this->redis();
        if ($redis === null) {
            return 0;
        }
        $redis->set(self::FAMILY_PREFIX . $familyId . ':revoked', '1', 'EX', $this->refreshTtl);
        $count = 0;
        foreach ([self::ACCESS_PREFIX, self::REFRESH_PREFIX] as $prefix) {
            foreach ($this->scanKeys($redis, $prefix . '*') as $key) {
                $payload = $redis->get($key);
                if (!$payload) {
                    continue;
                }
                $row = json_decode((string) $payload, true) ?: [];
                if (
                    (string) ($row['account_id'] ?? '') === $accountId
                    && (string) ($row['family_id'] ?? '') === $familyId
                ) {
                    $redis->del($key);
                    $count++;
                }
            }
        }
        return $count;
    }

    /**
     * Return Redis keys while handling both the phpredis and test-stub scan
     * result shapes. Illuminate's connection returns [cursor, keys], while
     * the narrow test double returns keys directly.
     *
     * @return list<string>
     */
    private function scanKeys(object $redis, string $pattern): array
    {
        $cursor = 0;
        $keys = [];
        do {
            $result = $redis->scan($cursor, ['match' => $pattern, 'count' => 200]);
            if (!is_array($result)) {
                break;
            }

            if (array_key_exists(0, $result) && is_array($result[1] ?? null)) {
                $cursor = (int) $result[0];
                $batch = $result[1];
            } else {
                $cursor = 0;
                $batch = $result;
            }

            foreach ($batch as $key) {
                if (is_string($key)) {
                    $keys[] = $key;
                }
            }
        } while ($cursor !== 0);

        return $keys;
    }

    /** Revoke the family of a refresh that was replayed (internal). */
    private function revokeFamily(string $familyId): void
    {
        $redis = $this->redis();
        if ($redis === null) {
            return;
        }
        $redis->set(self::FAMILY_PREFIX . $familyId . ':revoked', '1', 'EX', $this->refreshTtl);
    }

    private function mint(string $prefix, string $accountId, string $kind, string $familyId, int $ttl): string
    {
        $raw  = self::randomToken();
        $key  = $prefix . self::hash($raw);
        $body = json_encode([
            'account_id' => $accountId,
            'kind'       => $kind,
            'family_id'  => $familyId,
            'exp'        => time() + $ttl,
        ], JSON_THROW_ON_ERROR);
        $redis = $this->redis();
        if ($redis === null) {
            // Fail-closed: caller treats this as UNAUTHENTICATED.
            throw new \RuntimeException('redis_unavailable');
        }
        $redis->setex($key, $ttl, $body);
        if ($prefix === self::REFRESH_PREFIX) {
            // Track latest refresh hash for reuse detection.
            $redis->setex(self::FAMILY_PREFIX . $familyId, $ttl, self::hash($raw));
        }
        return $raw;
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
            Logger::error('redis.unavailable', ['err' => $e->getMessage()]);
            return null;
        }
    }

    private static function randomToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private static function randomId(): string
    {
        return bin2hex(random_bytes(8));
    }

    public static function hash(string $raw): string
    {
        return hash('sha256', $raw);
    }
}
