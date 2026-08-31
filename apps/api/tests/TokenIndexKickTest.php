<?php

declare(strict_types=1);

namespace Tests;

use App\service\TokenService;
use PHPUnit\Framework\TestCase;

final class TokenIndexKickTest extends TestCase
{
    private InMemoryRedis $redis;
    private TokenService $tokens;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
        $this->tokens = new TokenService(accessTtl: 900, refreshTtl: 604800);
    }

    public function testKickFamilyUsesIndexWithoutScan(): void
    {
        $issued = $this->tokens->issue('42', TokenService::KIND_LEARNER);
        self::assertNotNull($issued);
        $familyId = (string) $issued['family_id'];
        $access = (string) $issued['access_token'];

        self::assertGreaterThan(0, $this->tokens->kickFamily('42', $familyId));
        self::assertNull($this->tokens->verifyAccess($access));
        self::assertSame([], $this->redis->sMembers('family:' . $familyId . ':access_keys'));
    }

    public function testKickAllRevokesEveryFamilyForAccount(): void
    {
        $first = $this->tokens->issue('77', TokenService::KIND_LEARNER);
        $second = $this->tokens->issue('77', TokenService::KIND_LEARNER);
        self::assertNotNull($first);
        self::assertNotNull($second);

        self::assertGreaterThan(0, $this->tokens->kickAll('77'));
        self::assertNull($this->tokens->verifyAccess((string) $first['access_token']));
        self::assertNull($this->tokens->verifyAccess((string) $second['access_token']));
    }
}
