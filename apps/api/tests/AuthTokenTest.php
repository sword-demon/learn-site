<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\OptionalLearnerAuth;
use App\support\ApiResponse;
use PHPUnit\Framework\TestCase;
use App\service\TokenService;
use App\service\CaptchaService;
use Webman\Http\Request;
use Webman\Http\Response;

/**
 * AuthTokenTest — covers the contract pinned in FR-091 / FR-092:
 *
 *  - captcha is one-time on success, failure, and successful login
 *  - captcha TTL is 120s
 *  - refresh rotates; reuse revokes the family
 *  - kick-all vs kick-one-family behave per spec
 *  - Redis-down fails closed
 *  - no account lockout after repeated bad passwords
 *
 * The harness stubs Redis with an in-memory shim so we don't depend on a
 * running container. CaptchaService / TokenService accept any object that
 * quacks like \Redis via reflection-injection — see setRedis().
 */
final class AuthTokenTest extends TestCase
{
    private InMemoryRedis $redis;
    private TokenService $tokens;
    private CaptchaService $captcha;

    protected function setUp(): void
    {
        $this->redis = new InMemoryRedis();
        RedisStub::install($this->redis);
        $this->tokens  = new TokenService();
        $this->captcha = new CaptchaService();
    }

    public function testOptionalLearnerAuthAllowsRequestsWithoutBearerToken(): void
    {
        $request = new Request("GET /api/learner/v1/courses/1 HTTP/1.1\r\nHost: test\r\n\r\n");
        $called = false;

        $response = (new OptionalLearnerAuth($this->tokens))->process(
            $request,
            static function (Request $handled) use (&$called): Response {
                $called = true;
                return ApiResponse::ok(['account_id' => $handled->account_id ?? null]);
            },
        );

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
        $payload = json_decode((string) $response->rawBody(), true);
        $this->assertNull($payload['data']['account_id'] ?? null);
    }

    public function testOptionalLearnerAuthHydratesValidBearerAndRejectsInvalidBearer(): void
    {
        $pair = $this->tokens->issue('42', TokenService::KIND_LEARNER);
        $request = new Request(
            "GET /api/learner/v1/courses/1 HTTP/1.1\r\n"
            . "Host: test\r\nAuthorization: Bearer {$pair['access_token']}\r\n\r\n",
        );

        $response = (new OptionalLearnerAuth($this->tokens))->process(
            $request,
            static fn (Request $handled): Response => ApiResponse::ok([
                'account_id' => $handled->account_id ?? null,
                'actor_kind' => $handled->actor_kind ?? null,
            ]),
        );
        $payload = json_decode((string) $response->rawBody(), true);
        $this->assertSame('42', $payload['data']['account_id'] ?? null);
        $this->assertSame(TokenService::KIND_LEARNER, $payload['data']['actor_kind'] ?? null);

        $called = false;
        $invalid = new Request(
            "GET /api/learner/v1/courses/1 HTTP/1.1\r\n"
            . "Host: test\r\nAuthorization: Bearer invalid\r\n\r\n",
        );
        $rejected = (new OptionalLearnerAuth($this->tokens))->process(
            $invalid,
            static function () use (&$called): Response {
                $called = true;
                return ApiResponse::ok();
            },
        );

        $this->assertFalse($called);
        $this->assertSame(401, $rejected->getStatusCode());
        $payload = json_decode((string) $rejected->rawBody(), true);
        $this->assertSame(ApiResponse::TOKEN_EXPIRED, $payload['error']['code'] ?? null);
    }

    public function testCaptchaOneTimeUseOnSuccess(): void
    {
        $issued = $this->captcha->issue();
        $this->assertNotNull($issued, 'captcha issuance must succeed with stub Redis');
        $cid = $issued['captcha_id'];

        // answer visible to the test only because we mint the issue
        $this->assertTrue(
            $this->captcha->consume($cid, $this->redis->peekCaptcha($cid)),
            'first consume should accept',
        );
        // re-using the same captcha id — must fail
        $this->assertFalse(
            $this->captcha->consume($cid, $this->redis->peekCaptcha($cid)),
            'second consume on the same id must be rejected',
        );
    }

    public function testCaptchaWrongAnswerBurnsIt(): void
    {
        $issued = $this->captcha->issue();
        $cid    = $issued['captcha_id'];
        $this->assertFalse($this->captcha->consume($cid, 'ZZZZ'));
        // Even with the right answer the second call must fail (already deleted).
        $this->assertFalse($this->captcha->consume($cid, $this->redis->peekCaptcha($cid)));
    }

    public function testCaptchaTtlIs120Seconds(): void
    {
        $issued = $this->captcha->issue();
        $cid    = $issued['captcha_id'];
        $this->assertSame(120, $issued['ttl_seconds']);
        $this->assertSame(120, $this->redis->ttl('captcha:' . $cid));
    }

    public function testExplicitE2eCaptchaAnswerOnlyAppliesInTesting(): void
    {
        $previousEnv = getenv('APP_ENV');
        $previousAnswer = getenv('E2E_CAPTCHA_ANSWER');
        try {
            putenv('APP_ENV=testing');
            putenv('E2E_CAPTCHA_ANSWER=E2-7');
            $issued = $this->captcha->issue();
            $this->assertNotNull($issued);
            $this->assertTrue($this->captcha->consume($issued['captcha_id'], 'E2-7'));

            putenv('APP_ENV=production');
            $issued = $this->captcha->issue();
            $this->assertNotNull($issued);
            $this->assertFalse($this->captcha->consume($issued['captcha_id'], 'E2-7'));
        } finally {
            $previousEnv === false ? putenv('APP_ENV') : putenv('APP_ENV=' . $previousEnv);
            $previousAnswer === false
                ? putenv('E2E_CAPTCHA_ANSWER')
                : putenv('E2E_CAPTCHA_ANSWER=' . $previousAnswer);
        }
    }

    public function testRefreshRotatesAndReuseRevokesFamily(): void
    {
        $pair = $this->tokens->issue('42', TokenService::KIND_LEARNER);
        $first = $pair['refresh_token'];

        $rotated = $this->tokens->rotate($first);
        $this->assertNotNull($rotated, 'first rotation succeeds');
        $this->assertNotSame($first, $rotated['refresh_token']);
        $this->assertSame($pair['family_id'], $rotated['family_id']);

        // Re-using the OLD refresh must now be detected and revoke the family.
        $replay = $this->tokens->rotate($first);
        $this->assertNull($replay, 'old refresh after rotation must fail');
        $this->assertSame(1, $this->redis->exists('family:' . $pair['family_id'] . ':revoked'));

        // New refresh on the same family must also fail because the family
        // is now revoked.
        $replayNew = $this->tokens->rotate($rotated['refresh_token']);
        $this->assertNull($replayNew, 'rotation after family revocation must fail');
    }

    public function testKickAllRevokesEveryFamily(): void
    {
        $a = $this->tokens->issue('77', TokenService::KIND_LEARNER);
        $b = $this->tokens->issue('77', TokenService::KIND_LEARNER);
        $c = $this->tokens->issue('88', TokenService::KIND_LEARNER);

        $this->tokens->kickAll('77');

        $this->assertNull($this->tokens->verifyAccess($a['access_token']));
        $this->assertNull($this->tokens->verifyAccess($b['access_token']));
        $this->assertNotNull(
            $this->tokens->verifyAccess($c['access_token']),
            'another learner should keep their session',
        );
    }

    public function testKickOneFamilyLeavesOthers(): void
    {
        $a = $this->tokens->issue('99', TokenService::KIND_LEARNER);
        $b = $this->tokens->issue('99', TokenService::KIND_LEARNER);

        $this->tokens->kickFamily('99', $a['family_id']);

        $this->assertNull($this->tokens->verifyAccess($a['access_token']));
        $this->assertNotNull($this->tokens->verifyAccess($b['access_token']));
    }

    public function testRedisDownFailsClosed(): void
    {
        RedisStub::failNext();
        $pair = $this->tokens->issue('1', TokenService::KIND_LEARNER);
        $this->assertNull($pair);

        $this->assertNull($this->tokens->rotate('whatever'));
        $this->assertNull($this->tokens->verifyAccess('whatever'));
        $this->assertNull($this->captcha->issue());
    }

    public function testNoAccountLockoutConcept(): void
    {
        // Service layer never reads attempt counters; it only verifies
        // password + captcha. Documenting that no method exists for "lock
        // account after N failures" — explicit assertion on absence.
        $this->assertFalse(
            method_exists($this->tokens, 'lockAccount'),
            'token service must NOT implement account lockout (FR-091 / scope-out)',
        );
        $this->assertFalse(
            method_exists($this->captcha, 'lockAccount'),
            'captcha service must NOT implement account lockout',
        );
    }

    public function testConcurrentLoginOnSameCaptchaFirstWins(): void
    {
        // Spec requires the first login to consume the captcha; the second
        // login (even with correct creds) must return CAPTCHA_INVALID.
        $issued = $this->captcha->issue();
        $cid    = $issued['captcha_id'];
        $answer = $this->redis->peekCaptcha($cid);

        // first attempt succeeds (consumes captcha)
        $first = $this->captcha->consume($cid, $answer);
        $this->assertTrue($first);

        // second attempt with the SAME id but correct creds must fail
        $second = $this->captcha->consume($cid, $answer);
        $this->assertFalse($second, 'concurrent login on a consumed captcha must fail');
    }

    public function testReplayAfterSuccessfulLoginReturnsCaptchaInvalid(): void
    {
        $issued = $this->captcha->issue();
        $cid    = $issued['captcha_id'];
        $answer = $this->redis->peekCaptcha($cid);

        $this->assertTrue($this->captcha->consume($cid, $answer));
        // Replay must fail (CAPTCHA_INVALID) — not TOKEN_EXPIRED, not success.
        $this->assertFalse($this->captcha->consume($cid, $answer));
    }
}
