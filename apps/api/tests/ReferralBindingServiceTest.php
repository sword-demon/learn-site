<?php

declare(strict_types=1);

namespace Tests;

use App\service\ReferralBindingService;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ReferralBindingServiceTest extends TestCase
{
    private ReferralBindingService $service;
    private ShareEntryService $shares;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        // ponytail: enable distribution so test share_entries snapshot
        // distribution_enabled_at_creation=1 — without this, every
        // minted entry is disabled-by-default and bindFromVisitor never
        // resolves a referrer.
        Db::name('site_settings')->where('key', 'distribution_config')->update([
            'value' => json_encode(['enabled' => true, 'max_levels' => 3, 'default_rate_bps' => 1000, 'settlement_delay_days' => 0], JSON_THROW_ON_ERROR),
        ]);
        $this->service = new ReferralBindingService();
        $this->shares = new ShareEntryService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    private function makeLearner(): int
    {
        $now = date('Y-m-d H:i:s');
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => 'ref-' . bin2hex(random_bytes(4)),
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
        return $accountId;
    }

    public function testBindFromVisitorSetsReferrerWhenVisitExists(): void
    {
        $referrer = $this->makeLearner();
        $entry = $this->shares->create($referrer, 'site', null);
        $visit = $this->shares->recordVisit($entry['plaintext_code'], null, null, null);

        $newLearner = $this->makeLearner();
        $resolved = $this->service->bindFromVisitor($newLearner, $visit['visitor_token'], (int) $entry['id']);
        self::assertSame($referrer, $resolved);

        $row = Db::name('learners')->where('account_id', $newLearner)->find();
        self::assertSame($referrer, (int) $row['referrer_learner_id']);
    }

    public function testBindIsIdempotent(): void
    {
        $referrerA = $this->makeLearner();
        $referrerB = $this->makeLearner();
        $entryA = $this->shares->create($referrerA, 'site', null);
        $visitA = $this->shares->recordVisit($entryA['plaintext_code'], null, null, null);

        $newLearner = $this->makeLearner();
        $first = $this->service->bindFromVisitor($newLearner, $visitA['visitor_token'], (int) $entryA['id']);
        self::assertSame($referrerA, $first);

        // Second bind attempt with a different referrer must NOT overwrite.
        $entryB = $this->shares->create($referrerB, 'site', null);
        $visitB = $this->shares->recordVisit($entryB['plaintext_code'], null, null, null);
        $second = $this->service->bindFromVisitor($newLearner, $visitB['visitor_token'], (int) $entryB['id']);
        self::assertSame($referrerA, $second, 'existing referrer must not be overwritten');

        $row = Db::name('learners')->where('account_id', $newLearner)->find();
        self::assertSame($referrerA, (int) $row['referrer_learner_id']);
    }

    public function testBindForbidsSelfReference(): void
    {
        $learner = $this->makeLearner();
        $result = $this->service->bind($learner, $learner);
        self::assertNull($result);
        $row = Db::name('learners')->where('account_id', $learner)->find();
        self::assertNull($row['referrer_learner_id']);
    }

    public function testBindReturnsNullWhenReferrerMissing(): void
    {
        $learner = $this->makeLearner();
        $result = $this->service->bind($learner, 999999999);
        self::assertNull($result);
    }

    public function testBindReturnsNullWithoutInputs(): void
    {
        $learner = $this->makeLearner();
        $result = $this->service->bindFromVisitor($learner);
        self::assertNull($result);
    }

    public function testWalkUpFollowsChain(): void
    {
        $a = $this->makeLearner();
        $b = $this->makeLearner();
        $c = $this->makeLearner();
        $d = $this->makeLearner();
        // chain: d → c → b → a
        Db::name('learners')->where('account_id', $d)->update(['referrer_learner_id' => $c]);
        Db::name('learners')->where('account_id', $c)->update(['referrer_learner_id' => $b]);
        Db::name('learners')->where('account_id', $b)->update(['referrer_learner_id' => $a]);

        $chain = $this->service->walkUp($d, 3);
        self::assertSame([$c, $b, $a], $chain);
    }

    public function testWalkUpCapsAtMaxLevels(): void
    {
        $a = $this->makeLearner();
        $b = $this->makeLearner();
        $c = $this->makeLearner();
        $d = $this->makeLearner();
        // chain: d → c → b → a (4 nodes, asking for 2)
        Db::name('learners')->where('account_id', $d)->update(['referrer_learner_id' => $c]);
        Db::name('learners')->where('account_id', $c)->update(['referrer_learner_id' => $b]);
        Db::name('learners')->where('account_id', $b)->update(['referrer_learner_id' => $a]);

        $chain = $this->service->walkUp($d, 2);
        self::assertSame([$c, $b], $chain);
    }

    public function testWalkUpBreaksOnCycle(): void
    {
        $a = $this->makeLearner();
        $b = $this->makeLearner();
        // create cycle: a → b → a (DB triggers would normally block this; we
        // inject via raw SQL bypassing the trigger for the test setup).
        Db::execute('SET @@SESSION.foreign_key_checks = 0');
        Db::name('learners')->where('account_id', $a)->update(['referrer_learner_id' => $b]);
        Db::name('learners')->where('account_id', $b)->update(['referrer_learner_id' => $a]);
        Db::execute('SET @@SESSION.foreign_key_checks = 1');

        $chain = $this->service->walkUp($a, 3);
        // First step returns b; second step would return a but we already
        // saw it → break.
        self::assertSame([$b], $chain);
    }
}