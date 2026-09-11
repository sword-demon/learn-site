<?php

declare(strict_types=1);

namespace Tests;

use App\controller\public\ShareLandingController;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * Spec: 016-course-distribution/T020 + T025.
 *   - GET /r/{code} writes share_visits and seeds the visitor cookie
 *   - No referrer / visitor_token leaks in the response body
 *   - scope=course → 302 to LEARN_SITE_PUBLIC_BASE/courses/{course_id}
 *   - scope=site   → 302 to LEARN_SITE_PUBLIC_BASE/
 *   - bad code / revoked code → 404 SHARE_NOT_FOUND
 *
 * Test harness wraps Request with an in-memory Cookie header so the
 * service's recordVisit() token-reuse path can be exercised without a
 * real HTTP client.
 */
final class ShareLandingControllerTest extends TestCase
{
    private const PUBLIC_BASE = 'https://learn.example.test';
    private int $learnerId;
    private ShareEntryService $service;
    private ShareLandingController $controller;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
        // Test container runs without .env; pin the public base here so the
        // controller's redirect Location header is deterministic.
        putenv('LEARN_SITE_PUBLIC_BASE=' . self::PUBLIC_BASE);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        // ponytail: T007 enforces DISTRIBUTION_DISABLED on create(); tests
        // for the landing controller still mint entries via ShareEntryService
        // so the global switch must be on here too.
        Db::name('site_settings')->where('key', 'distribution_config')->update([
            'value' => json_encode(['enabled' => true, 'max_levels' => 3, 'default_rate_bps' => 1000, 'settlement_delay_days' => 0], JSON_THROW_ON_ERROR),
        ]);
        $now = date('Y-m-d H:i:s');
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => 'landing-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $accountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = $accountId;
        $this->service = new ShareEntryService();
        $this->controller = new ShareLandingController($this->service);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testCourseScopeRedirectsToCourseDetail(): void
    {
        $entry = $this->service->create($this->learnerId, 'course', 7777);
        $response = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(self::PUBLIC_BASE . '/courses/7777', $response->getHeader('Location'));
        self::assertSame('', (string) $response->rawBody(), 'redirect body must be empty');
    }

    public function testSiteScopeRedirectsToHomepage(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $response = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        self::assertSame(302, $response->getStatusCode());
        self::assertSame(self::PUBLIC_BASE . '/', $response->getHeader('Location'));
    }

    public function testVisitorCookieIsHttpOnlyAndReused(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        // First hit mints a cookie.
        $first = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        $cookies1 = $this->flattenSetCookie($first->getHeader('Set-Cookie'));
        self::assertStringContainsString('distribution_visitor_token=', $cookies1);
        self::assertStringContainsString('HttpOnly', $cookies1);
        // Extract the cookie value.
        $token = $this->extractCookieValue($cookies1);
        self::assertSame(32, strlen($token));
        // Second hit with that token in Cookie header reuses the same token.
        $second = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], $token),
            $entry['plaintext_code'],
        );
        $cookies2 = $this->flattenSetCookie($second->getHeader('Set-Cookie'));
        $token2 = $this->extractCookieValue($cookies2);
        self::assertSame($token, $token2, 'existing token must be reused, not rotated');
    }

    public function testResponseBodyDoesNotLeakReferrerOrVisitorToken(): void
    {
        $entry = $this->service->create($this->learnerId, 'course', 42);
        $response = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        $body = (string) $response->rawBody();
        // ponytail: empty redirect body must not leak referrer / plaintext code.
        self::assertSame('', $body);
        self::assertStringNotContainsString((string) $this->learnerId, $body);
        self::assertStringNotContainsString((string) $entry['plaintext_code'], $body);
    }

    public function testBadCodeReturnsShareNotFound(): void
    {
        $response = $this->controller->show(
            new LandingRequest('NOT-A-CODE!!!', ''),
            'NOT-A-CODE!!!',
        );
        self::assertSame(404, $response->getStatusCode());
        $body = json_decode((string) $response->rawBody(), true);
        self::assertSame('SHARE_NOT_FOUND', $body['error']['message']);
    }

    public function testRevokedEntryReturnsShareNotFound(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $this->service->revoke($this->learnerId, (int) $entry['id']);
        $response = $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        self::assertSame(404, $response->getStatusCode());
    }

    public function testValidHitPersistsVisit(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        $count = (int) Db::name('share_visits')
            ->where('share_entry_id', $entry['id'])
            ->count();
        self::assertSame(1, $count);
    }

    public function testValidHitWritesDistributionAuditLog(): void
    {
        $entry = $this->service->create($this->learnerId, 'course', 4242);
        $this->controller->show(
            new LandingRequest($entry['plaintext_code'], ''),
            $entry['plaintext_code'],
        );
        // ponytail: every public landing hit must land a row in
        // distribution_audit_log so ops can reconcile share_visits.
        $row = Db::name('distribution_audit_log')
            ->where('action', 'landing.hit')
            ->where('subject_type', 'share_entry')
            ->where('subject_id', (int) $entry['id'])
            ->find();
        self::assertNotNull($row, 'landing.hit row must be written for every public hit');
        self::assertSame('system', $row['actor_type']);
        self::assertNull($row['actor_id']);
    }

    public function testBadCodeDoesNotWriteAuditLog(): void
    {
        $response = $this->controller->show(
            new LandingRequest('NOT-A-CODE!!!', ''),
            'NOT-A-CODE!!!',
        );
        self::assertSame(404, $response->getStatusCode());
        // ponytail: bad-code path must NOT pollute audit log; only valid hits count.
        $count = (int) Db::name('distribution_audit_log')
            ->where('action', 'landing.hit')
            ->count();
        self::assertSame(0, $count);
    }

    private function extractCookieValue(string $setCookie): string
    {
        // ponytail: first segment of Set-Cookie is "name=value"; the rest
        // (Path, HttpOnly, Max-Age, SameSite) is irrelevant to the token.
        $head = explode(';', $setCookie, 2)[0];
        return explode('=', $head, 2)[1] ?? '';
    }

    // ponytail: Webman's getHeader() returns array|string depending on
    // how many headers were set; flatten to a single joined string so
    // downstream assertions stay linear.
    private function flattenSetCookie(mixed $header): string
    {
        if (is_array($header)) {
            return implode("\n", array_map('strval', $header));
        }
        return (string) $header;
    }
}

/**
 * Minimal Webman\Request subclass for in-memory landing tests. Carries the
 * inbound visitor cookie (when present) and pins the request method / URL
 * to what route.php matches (`GET /r/{code}`).
 */
final class LandingRequest extends Request
{
    public function __construct(string $code, string $visitorToken)
    {
        $cookieHeader = $visitorToken !== ''
            ? "Cookie: distribution_visitor_token={$visitorToken}\r\n"
            : '';
        $raw = "GET /r/{$code} HTTP/1.1\r\nHost: test\r\nUser-Agent: phpunit\r\n{$cookieHeader}\r\n";
        parent::__construct($raw);
    }

    public function getRealIp(bool $safeMode = true): string
    {
        return '203.0.113.42';
    }
}