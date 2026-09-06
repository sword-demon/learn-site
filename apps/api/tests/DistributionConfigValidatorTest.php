<?php

declare(strict_types=1);

namespace app\tests;

use App\service\BusinessException;
use App\support\DistributionConfigValidator;
use PHPUnit\Framework\TestCase;

/**
 * Pure unit tests for the SC-003 / FR-010 hard cap enforcement. No DB,
 * no Webman runtime — these assert the in-memory guard before any
 * migration or service gets involved.
 */
final class DistributionConfigValidatorTest extends TestCase
{
    private function baseline(array $overrides = []): array
    {
        return array_merge([
            'enabled' => true,
            'level_cap' => 3,
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
            'base' => 'order_paid',
            'per_order_cap_cents' => 5000,
            'per_learner_course_cap_cents' => 50000,
            'per_learner_total_cap_cents' => null,
            'settlement' => 'order_settled',
            'refund_void_rule' => 'void_all',
            'payout_form' => 'cash_record_only',
            'learner_can_view_detail' => true,
        ], $overrides);
    }

    public function testBaselineConfigPasses(): void
    {
        DistributionConfigValidator::assertValidConfig($this->baseline());
        $this->assertTrue(true, 'baseline config is accepted');
    }

    public function testLevelCapThreeIsAccepted(): void
    {
        DistributionConfigValidator::assertValidConfig($this->baseline(['level_cap' => 3]));
        $this->assertTrue(true);
    }

    public function testLevelCapFourIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/LEVEL_CAP_EXCEEDS_HARD_LIMIT/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['level_cap' => 4]));
    }

    public function testLevelCapZeroIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        DistributionConfigValidator::assertValidConfig($this->baseline(['level_cap' => 0]));
    }

    public function testLevelCapNegativeIsRejected(): void
    {
        $this->expectException(BusinessException::class);
        DistributionConfigValidator::assertValidConfig($this->baseline(['level_cap' => -1]));
    }

    public function testRatiosAboveOneAreRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/RATIO_OUT_OF_RANGE:level1_pct/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['level1_pct' => 1.5]));
    }

    public function testRatiosBelowZeroAreRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/RATIO_OUT_OF_RANGE:level2_pct/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['level2_pct' => -0.01]));
    }

    public function testRatioMustBeNumeric(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/INVALID_RATIO:level3_pct/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['level3_pct' => 'not a number']));
    }

    public function testNegativeCapsAreRejected(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/CAP_NEGATIVE:per_order_cap_cents/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['per_order_cap_cents' => -1]));
    }

    public function testEnabledMustBeBoolean(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/INVALID_ENABLED_FLAG/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['enabled' => 'yes']));
    }

    public function testLearnerViewFlagMustBeBoolean(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessageMatches('/INVALID_LEARNER_VIEW_FLAG/');
        DistributionConfigValidator::assertValidConfig($this->baseline(['learner_can_view_detail' => 1]));
    }

    public function testTheoreticalMaxCommissionCentsSumsAllLevels(): void
    {
        $cfg = $this->baseline([
            'level1_pct' => 0.10,
            'level2_pct' => 0.05,
            'level3_pct' => 0.02,
        ]);
        // 10 + 5 + 2 = 17%, on 10000 cents → 1700.
        $this->assertSame(1700, DistributionConfigValidator::theoreticalMaxCommissionCents($cfg, 10000));
    }

    public function testTheoreticalMaxCommissionCentsFloors(): void
    {
        $cfg = $this->baseline([
            'level1_pct' => 0.01,
            'level2_pct' => 0.01,
            'level3_pct' => 0.01,
        ]);
        // 0.03 * 10 = 0.3 → floor to 0
        $this->assertSame(0, DistributionConfigValidator::theoreticalMaxCommissionCents($cfg, 10));
    }

    public function testTheoreticalMaxHandlesMissingRatios(): void
    {
        // Defensive: missing keys default to 0, never throw.
        $this->assertSame(0, DistributionConfigValidator::theoreticalMaxCommissionCents([], 1000));
    }
}