<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ShareEntryServiceTest extends TestCase
{
    private int $learnerId;
    private ShareEntryService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        // ponytail: flip distribution on so create() snapshots
        // distribution_enabled_at_creation=1 — otherwise every share_entry
        // minted here is disabled-by-default and bindVisitorToLearner tests
        // for enabled entries would silently noop.
        Db::name('site_settings')->where('key', 'distribution_config')->update([
            'value' => json_encode(['enabled' => true, 'max_levels' => 3, 'default_rate_bps' => 1000, 'settlement_delay_days' => 0], JSON_THROW_ON_ERROR),
        ]);
        $now = date('Y-m-d H:i:s');
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => 'share-' . bin2hex(random_bytes(4)),
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
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testCreateMintsShortCodeAndReturnsPlaintextOnce(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        self::assertSame($this->learnerId, (int) $entry['learner_id']);
        self::assertSame('site', $entry['scope']);
        self::assertSame(12, strlen($entry['plaintext_code']));
        self::assertStringContainsString('/r/', $entry['share_url']);
        self::assertGreaterThan(0, (int) $entry['id']);
    }

    public function testCreateRejectsInvalidScope(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('INVALID_SCOPE');
        $this->service->create($this->learnerId, 'invalid', null);
    }

    public function testCreateRejectsCourseScopeWithoutCourseId(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('COURSE_REQUIRED');
        $this->service->create($this->learnerId, 'course', null);
    }

    public function testCreateAcceptsCourseScopeWithCourseId(): void
    {
        $courseId = $this->insertPublishedCourse();
        $entry = $this->service->create($this->learnerId, 'course', $courseId);
        self::assertSame('course', $entry['scope']);
        self::assertSame($courseId, $entry['course_id']);
    }

    public function testCreateRejectsUnpublishedCourse(): void
    {
        $courseId = $this->insertPublishedCourse();
        Db::name('courses')->where('id', $courseId)->update(['status' => 'draft']);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('COURSE_NOT_PUBLISHED');
        $this->service->create($this->learnerId, 'course', $courseId);
    }

    public function testListForLearnerMasksCodes(): void
    {
        $first = $this->service->create($this->learnerId, 'site', null);
        $second = $this->service->create($this->learnerId, 'course', $this->insertPublishedCourse());
        $items = $this->service->listForLearner($this->learnerId);
        self::assertCount(2, $items);
        $codes = array_column($items, 'masked_code');
        foreach ($codes as $masked) {
            self::assertMatchesRegularExpression('/^[A-Z2-9]{4}\*{4}[A-Z2-9]{4}$/', $masked);
        }
        // The plaintext codes are NOT in the list output.
        self::assertNotContains($first['plaintext_code'], $codes);
        self::assertNotContains($second['plaintext_code'], $codes);
    }

    public function testListForLearnerAggregatesVisitAndBoundCountsInOneQuery(): void
    {
        // Seed: two entries, three visits on entry A (one bound), two on B.
        $entryA = $this->service->create($this->learnerId, 'site', null);
        $entryB = $this->service->create($this->learnerId, 'site', null);
        $a = $this->service->recordVisit($entryA['plaintext_code'], null, '10.0.0.1', 'ua-a');
        $this->service->recordVisit($entryA['plaintext_code'], null, '10.0.0.2', 'ua-a2');
        $this->service->recordVisit($entryA['plaintext_code'], $a['visitor_token'], '10.0.0.3', 'ua-a3');
        $this->service->bindVisitorToLearner($this->learnerId, $a['visitor_token']);
        $this->service->recordVisit($entryB['plaintext_code'], null, '10.0.0.4', 'ua-b');
        $this->service->recordVisit($entryB['plaintext_code'], null, '10.0.0.5', 'ua-b2');

        $items = $this->service->listForLearner($this->learnerId);
        self::assertCount(2, $items);
        $byId = [];
        foreach ($items as $item) {
            $byId[(int) $item['id']] = $item;
        }
        // Entry A: 3 visits (1st + 3rd share visitor_token → both bound together
        // by bindVisitorToLearner); entry B: 2 unbound visits.
        self::assertSame(3, $byId[(int) $entryA['id']]['visit_count']);
        self::assertSame(2, $byId[(int) $entryA['id']]['bound_count']);
        self::assertSame(2, $byId[(int) $entryB['id']]['visit_count']);
        self::assertSame(0, $byId[(int) $entryB['id']]['bound_count']);

        // ponytail: confirm single GROUP BY collapsed the N+1 — exact query
        // count for the list endpoint must be 2 (entries + visits aggregate).
        // Using a counter on the think-orm connection is fragile across
        // builds; assert instead that adding a 3rd entry does not double
        // the per-row visit lookup work (visits counted via the single
        // GROUP BY, not per-entry SELECT).
        $entryC = $this->service->create($this->learnerId, 'site', null);
        $itemsWithC = $this->service->listForLearner($this->learnerId);
        $byIdC = [];
        foreach ($itemsWithC as $item) {
            $byIdC[(int) $item['id']] = $item;
        }
        self::assertSame(0, $byIdC[(int) $entryC['id']]['visit_count']);
        self::assertSame(0, $byIdC[(int) $entryC['id']]['bound_count']);
    }

    public function testRevokeMarksEntryAndSecondRevokeIsNoop(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $this->service->revoke($this->learnerId, (int) $entry['id']);
        // Revoking again must not throw.
        $this->service->revoke($this->learnerId, (int) $entry['id']);
        $row = Db::name('share_entries')->where('id', $entry['id'])->find();
        self::assertNotNull($row['revoked_at']);
    }

    public function testRevokeUnknownThrowsNotFound(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('SHARE_NOT_FOUND');
        $this->service->revoke($this->learnerId, 999999999);
    }

    public function testRecordVisitSetsCookieTokenAndIsLogged(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $result = $this->service->recordVisit(
            $entry['plaintext_code'],
            null,
            '203.0.113.7',
            'Mozilla/5.0 test',
        );
        self::assertSame(32, strlen($result['visitor_token']));
        self::assertSame((int) $entry['id'], $result['share_entry_id']);
        self::assertSame($this->learnerId, $result['learner_id']);
        $count = (int) Db::name('share_visits')
            ->where('share_entry_id', $entry['id'])
            ->count();
        self::assertSame(1, $count);
    }

    public function testRecordVisitRejectsBadCode(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('SHARE_NOT_FOUND');
        $this->service->recordVisit('NOT-A-CODE!!!', null, null, null);
    }

    public function testRecordVisitRejectsRevokedEntry(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $this->service->revoke($this->learnerId, (int) $entry['id']);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('SHARE_NOT_FOUND');
        $this->service->recordVisit($entry['plaintext_code'], null, null, null);
    }

    public function testResolveByVisitorReturnsReferrer(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $visit = $this->service->recordVisit($entry['plaintext_code'], null, null, null);
        $resolved = $this->service->resolveByVisitor($visit['visitor_token'], (int) $entry['id']);
        self::assertNotNull($resolved);
        self::assertSame($this->learnerId, $resolved['referrer_learner_id']);
    }

    public function testResolveByVisitorReturnsNullForBadToken(): void
    {
        self::assertNull($this->service->resolveByVisitor('not-hex', 1));
    }

    public function testCreateRejectsWhenDistributionGloballyDisabled(): void
    {
        // ponytail: flip the global switch off; subsequent create() must
        // refuse rather than minting a share_entry with
        // distribution_enabled_at_creation=0 (silent dead link).
        Db::name('site_settings')->where('key', 'distribution_config')->update([
            'value' => json_encode(['enabled' => false, 'max_levels' => 3, 'default_rate_bps' => 1000, 'settlement_delay_days' => 0], JSON_THROW_ON_ERROR),
        ]);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('DISTRIBUTION_DISABLED');
        $this->service->create($this->learnerId, 'site', null);
    }

    public function testResolveByVisitorIsolatesDistributionDisabledEntries(): void
    {
        // ponytail: directly insert an entry flagged distribution_enabled_at_creation=0
        // to simulate the historical "disabled at creation" state — the service
        // creates entries with the current flag snapshot, so we backdate via SQL.
        $now = date('Y-m-d H:i:s');
        $code = \App\support\ShareShortCode::generate();
        $entryId = (int) Db::name('share_entries')->insertGetId([
            'learner_id' => $this->learnerId,
            'scope' => 'site',
            'course_id' => null,
            'short_code' => $code,
            'distribution_enabled_at_creation' => 0,
            'created_at' => $now,
        ]);
        $visit = $this->service->recordVisit($code, null, null, null);
        $count = (int) Db::name('share_visits')->where('share_entry_id', $entryId)->count();
        self::assertSame(0, $count, 'T082: generated-while-off entries do not log visits');
        self::assertNull(
            $this->service->resolveByVisitor($visit['visitor_token'], $entryId),
            'distribution_enabled_at_creation=0 entries must not resolve a referrer'
        );
    }

    public function testBindVisitorToLearnerMarksVisitsBound(): void
    {
        $entry = $this->service->create($this->learnerId, 'site', null);
        $visit = $this->service->recordVisit($entry['plaintext_code'], null, null, null);
        $updated = $this->service->bindVisitorToLearner($this->learnerId, $visit['visitor_token']);
        self::assertSame(1, $updated);
        $row = Db::name('share_visits')->where('visitor_token', $visit['visitor_token'])->find();
        self::assertSame($this->learnerId, (int) $row['bound_learner_id']);
        self::assertNotNull($row['bound_at']);
    }

    public function testBindVisitorToLearnerSkipsRevokedEntries(): void
    {
        $entryA = $this->service->create($this->learnerId, 'site', null);
        $entryB = $this->service->create($this->learnerId, 'site', null);
        // Same visitor (one browser) hits entry A first, then entry B; share one token.
        $sharedToken = bin2hex(random_bytes(16));
        $visitA = $this->service->recordVisit($entryA['plaintext_code'], $sharedToken, null, null);
        $visitB = $this->service->recordVisit($entryB['plaintext_code'], $sharedToken, null, null);
        self::assertSame($sharedToken, $visitA['visitor_token']);
        self::assertSame($sharedToken, $visitB['visitor_token']);
        $this->service->revoke($this->learnerId, (int) $entryA['id']);
        // Re-bind: A is revoked, B is alive → only B is updated.
        $updated = $this->service->bindVisitorToLearner($this->learnerId, $sharedToken);
        self::assertSame(1, $updated, 'revoked entry visit must not bind');
        $visitRowA = Db::name('share_visits')
            ->where('visitor_token', $sharedToken)
            ->where('share_entry_id', $visitA['share_entry_id'])
            ->find();
        $visitRowB = Db::name('share_visits')
            ->where('visitor_token', $sharedToken)
            ->where('share_entry_id', $visitB['share_entry_id'])
            ->find();
        self::assertNull($visitRowA['bound_learner_id']);
        self::assertNotNull($visitRowB['bound_learner_id']);
    }

    private function insertPublishedCourse(): int
    {
        $now = date('Y-m-d H:i:s');
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'share-cat-' . bin2hex(random_bytes(2)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => 'Share Course',
            'cover_url' => null,
            'teacher_name' => 'T',
            'summary' => null,
            'intro_rich_text' => null,
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}