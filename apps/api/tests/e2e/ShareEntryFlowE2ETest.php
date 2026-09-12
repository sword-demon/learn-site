<?php

declare(strict_types=1);

namespace Tests;

use App\service\DistributionConfigService;
use App\service\ReferralBindingService;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * T027 / SC-005 / SC-006 — the US1 flow end to end: learner A mints a share
 * entry, an anonymous visitor opens it (visit recorded, token issued), the
 * visitor registers with an unregistered phone and gets A as referrer. A
 * second registration on the same visitor token gets nothing, and an
 * existing learner B visiting the link stays unbound.
 */
final class ShareEntryFlowE2ETest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testShareVisitRegisterBindFlow(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'e2e-share-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 5000,
            'per_learner_course_cap_cents' => 50000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled_after_refund_window',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ]);
        $learnerA = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '136' . random_int(10000000, 99999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $learnerA, 'created_at' => $now, 'updated_at' => $now]);

        // 1) Learner A mints a site-wide share entry — plaintext returned once.
        $shares = new ShareEntryService();
        $entry = $shares->create($learnerA, 'site', null);
        self::assertNotEmpty($entry['plaintext_code']);
        self::assertStringContainsString($entry['short_code'], $entry['share_url']);
        self::assertNotContains(
            $entry['plaintext_code'],
            array_column($shares->listForLearner($learnerA), 'masked_code'),
            'later reads must only ever see the masked code',
        );

        // 2) Anonymous visitor opens the link — visit recorded, token issued.
        $token = $shares->recordVisit($entry['short_code'], null, '203.0.113.9', 'vitest-agent')['visitor_token'];
        self::assertSame(32, strlen($token));
        $visit = Db::name('share_visits')
            ->where('share_entry_id', $entry['id'])
            ->where('visitor_token', $token)
            ->find();
        self::assertIsArray($visit);
        self::assertNull($visit['bound_learner_id']);

        // 3) The visitor registers an unregistered phone → bound to A.
        $binding = new ReferralBindingService();
        $newPhone = '137' . random_int(10000000, 99999999);
        $newAccountId = $this->register($newPhone, $now);
        $referrer = $binding->bindFromVisitor($newAccountId, $token, $entry['id']);
        self::assertSame($learnerA, $referrer);
        $newLearner = Db::name('learners')->where('account_id', $newAccountId)->find();
        self::assertIsArray($newLearner);
        self::assertSame($learnerA, (int) $newLearner['referrer_learner_id']);

        // bound_count on the entry goes to 1.
        $listed = $shares->listForLearner($learnerA);
        self::assertSame(1, (int) $listed[0]['bound_count']);

        // 4) A second registration on the same visitor token gets no referrer.
        $secondAccountId = $this->register('135' . random_int(10000000, 99999999), $now);
        self::assertNull($binding->bindFromVisitor($secondAccountId, $token, $entry['id']));
        $secondLearner = Db::name('learners')->where('account_id', $secondAccountId)->find();
        self::assertIsArray($secondLearner);
        self::assertNull($secondLearner['referrer_learner_id']);
        self::assertSame(1, (int) $shares->listForLearner($learnerA)[0]['bound_count']);

        // 5) Existing learner B opens the link — B stays unbound.
        $learnerB = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '134' . random_int(10000000, 99999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $learnerB, 'created_at' => $now, 'updated_at' => $now]);
        $bReferrer = Db::name('learners')->where('account_id', $learnerB)->value('referrer_learner_id');
        self::assertEmpty($bReferrer);
        $shares->recordVisit($entry['short_code'], null, '203.0.113.10', 'vitest-agent');
        $bAfter = Db::name('learners')->where('account_id', $learnerB)->value('referrer_learner_id');
        self::assertEmpty($bAfter, 'visiting a share link never retro-binds an existing learner');
    }

    private function register(string $phone, string $now): int
    {
        $accountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => $phone,
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $accountId, 'created_at' => $now, 'updated_at' => $now]);
        return $accountId;
    }
}
