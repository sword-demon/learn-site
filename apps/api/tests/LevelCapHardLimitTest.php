<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\DistributionConfigService;
use App\support\DistributionConfigValidator;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * SC-003 — level_cap ≤ 3 must be rejected at all three layers:
 * app validator, config service, and the DB CHECK constraint.
 */
final class LevelCapHardLimitTest extends TestCase
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

    private function input(int $levelCap): array
    {
        return [
            'enabled' => true,
            'level_cap' => $levelCap,
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
        ];
    }

    public function testValidatorRejectsLevelCap4And0WithHardConstraintWording(): void
    {
        foreach ([4, 0] as $cap) {
            try {
                DistributionConfigValidator::assertValidConfig($this->input($cap));
                self::fail("level_cap={$cap} must be rejected by the validator");
            } catch (BusinessException $e) {
                self::assertStringContainsString('合规硬约束', $e->getMessage());
            }
        }
        foreach ([1, 2, 3] as $cap) {
            DistributionConfigValidator::assertValidConfig($this->input($cap));
            $this->addToAssertionCount(1);
        }
    }

    public function testServiceRejectsLevelCap4BeforeWrite(): void
    {
        $now = date('Y-m-d H:i:s');
        $staff = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'cap-staff-' . bin2hex(random_bytes(4)),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/LEVEL_CAP_EXCEEDS_HARD_LIMIT/');
        (new DistributionConfigService())->updateConfig($staff, $this->input(4));
    }

    public function testDbCheckRejectsLevelCapAbove3AndBelow1(): void
    {
        Db::name('site_settings')->where('key', 'distribution_config')->delete();
        foreach ([4, 0] as $cap) {
            try {
                Db::execute(
                    "INSERT INTO site_settings (`key`, `value`, `updated_at`)
                     VALUES ('distribution_config', :value, NOW())",
                    ['value' => json_encode(['level_cap' => $cap, 'enabled' => true])],
                );
                self::fail("DB CHECK must reject level_cap={$cap}");
            } catch (\Throwable $e) {
                // MySQL 3819: CHECK constraint violated — the storage layer
                // backstop for an admin bypassing the service.
                self::assertStringContainsStringIgnoringCase('chk_distribution_level_cap', $e->getMessage());
            }
        }
    }
}
