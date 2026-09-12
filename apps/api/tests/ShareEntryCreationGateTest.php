<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\DistributionConfigService;
use App\service\ShareEntryService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * T077 — the global switch gates share entry creation at the service layer:
 * enabled=false → 409-equivalent BusinessException, nothing written;
 * enabled=true → normal minting resumes.
 */
final class ShareEntryCreationGateTest extends TestCase
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

    public function testCreationRefusedAndNothingWrittenWhenDisabled(): void
    {
        $learnerId = $this->setupConfig(false);
        try {
            (new ShareEntryService())->create($learnerId, 'site', null);
            self::fail('creation must be refused while disabled');
        } catch (BusinessException $e) {
            self::assertSame('DISTRIBUTION_DISABLED', $e->getMessage());
        }
        self::assertSame(0, (int) Db::name('share_entries')->where('learner_id', $learnerId)->count());
    }

    public function testCreationResumesWhenEnabled(): void
    {
        $learnerId = $this->setupConfig(true);
        $entry = (new ShareEntryService())->create($learnerId, 'site', null);
        self::assertSame(1, (int) Db::name('share_entries')->where('learner_id', $learnerId)->count());
        self::assertSame($entry['plaintext_code'], $entry['short_code']);
        self::assertTrue($entry['distribution_enabled_at_creation']);
    }

    /**
     * @return int learner account id
     */
    private function setupConfig(bool $enabled): int
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'gate-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        (new DistributionConfigService())->updateConfig($staff, [
            'enabled' => $enabled,
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
        $learner = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert(['account_id' => $learner, 'created_at' => $now, 'updated_at' => $now]);
        return $learner;
    }
}
