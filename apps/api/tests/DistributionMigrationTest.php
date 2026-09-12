<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * Source-level assertions for the 016-course-distribution migration. Mirrors
 * what MigrationSafetyTest does for the entitlement fix: a real DB roundtrip
 * requires `make rebuild-api && make migrate` which is heavyweight to run per
 * test; the source-grep approach here pins the table/column names and the
 * SC-003 compliance constraints so a future change cannot silently remove
 * the ceiling.
 */
final class DistributionMigrationTest extends TestCase
{
    private string $migrationSource;

    protected function setUp(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/20260906000001_distribution.php';
        self::assertFileExists($path, 'distribution migration file must exist');
        $this->migrationSource = (string) file_get_contents($path);
    }

    public function testCreatesAllFiveTables(): void
    {
        foreach (
            [
                'share_entries',
                'share_visits',
                'distribution_course_overrides',
                'commission_records',
                'distribution_audit_log',
            ] as $table
        ) {
            self::assertStringContainsString("'{$table}'", $this->migrationSource);
        }
    }

    public function testExtendsLearnersWithReferrerColumn(): void
    {
        self::assertStringContainsString("'referrer_learner_id'", $this->migrationSource);
        self::assertStringContainsString('idx_learners_referrer', $this->migrationSource);
        self::assertStringContainsString('fk_learners_referrer', $this->migrationSource);
    }

    public function testCommissionLevelCheckEnforcesHardCap(): void
    {
        self::assertStringContainsString('chk_commission_level', $this->migrationSource);
        self::assertStringContainsString('CHECK (level BETWEEN 1 AND 3)', $this->migrationSource);
    }

    public function testConfigLevelCapCheckEnforcesHardCap(): void
    {
        self::assertStringContainsString('chk_distribution_level_cap', $this->migrationSource);
        self::assertStringContainsString("`key` <> 'distribution_config'", $this->migrationSource);
        self::assertStringContainsString("'$.level_cap'", $this->migrationSource);
        self::assertStringContainsString('<= 3', $this->migrationSource);
        self::assertStringNotContainsString('UNSIGNED) BETWEEN 1 AND 3)', $this->migrationSource);
    }

    public function testShippedSeedUsesOriginalSettlementLiteral(): void
    {
        self::assertStringContainsString("'settlement', 'order_settled'", $this->migrationSource);
        self::assertStringNotContainsString(
            "'settlement', 'order_settled_after_refund_window'",
            $this->migrationSource,
        );
    }

    public function testForwardMigrationTightensLevelCapAndSettlement(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/20260911000001_distribution_settlement_and_level_cap.php';
        self::assertFileExists($path, 'schema changes after a shipped migration must be a new forward file');
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('chk_distribution_level_cap', $source);
        self::assertStringContainsString('BETWEEN 1 AND 3', $source);
        self::assertStringNotContainsString('UNSIGNED) BETWEEN 1 AND 3)', $source);
        self::assertStringContainsString('order_settled_after_refund_window', $source);
        self::assertStringContainsString('DROP CHECK chk_distribution_level_cap', $source);
    }

    public function testReferrerImmutableTriggerBlocksUpdateAndDelete(): void
    {
        self::assertStringContainsString('trg_learners_referrer_immutable_update', $this->migrationSource);
        self::assertStringContainsString('trg_learners_referrer_immutable_delete', $this->migrationSource);
        self::assertStringContainsString('referrer_learner_id is immutable once set', $this->migrationSource);
        self::assertStringContainsString('cannot be hard-deleted', $this->migrationSource);
    }

    public function testReferrerFkCascadesOnUpdateAndRestrictsOnDelete(): void
    {
        // specs/016-course-distribution/data-model.md:23 — referrer_learner_id
        // FK must be ON DELETE RESTRICT (don't orphan commission rows) but ON
        // UPDATE CASCADE (a learner-id change propagates so no stale pointers).
        // The PHP string concat is line-sensitive; assert on the exact pair.
        self::assertStringContainsString('fk_learners_referrer', $this->migrationSource);
        self::assertStringContainsString('ON DELETE RESTRICT ON UPDATE CASCADE', $this->migrationSource);
        self::assertStringNotContainsString('ON UPDATE RESTRICT', $this->migrationSource);
    }

    public function testConfigLevelCapCheckIsIdempotentAndDroppedInDown(): void
    {
        // The ALTER TABLE … ADD CONSTRAINT chk_distribution_level_cap must
        // guard before adding so re-running the migration does not trip MySQL
        // 1061 "Duplicate key name". Phinx 0.16 has no Table::hasCheckConstraint,
        // so the guard queries information_schema. down() must drop it back out.
        self::assertStringContainsString('chk_distribution_level_cap', $this->migrationSource);
        self::assertStringContainsString('hasNamedCheckConstraint', $this->migrationSource);
        self::assertStringContainsString('information_schema.TABLE_CONSTRAINTS', $this->migrationSource);
        self::assertStringContainsString('DROP CONSTRAINT IF EXISTS chk_distribution_level_cap', $this->migrationSource);
    }

    public function testSeedsDefaultConfigRow(): void
    {
        self::assertStringContainsString("'distribution_config'", $this->migrationSource);
        self::assertStringContainsString("'level_cap', 3", $this->migrationSource);
        self::assertStringContainsString("'enabled', false", $this->migrationSource);
    }

    public function testDownDropsTriggersAndTables(): void
    {
        self::assertStringContainsString('DROP TRIGGER IF EXISTS', $this->migrationSource);
        foreach (
            [
                'distribution_audit_log',
                'commission_records',
                'distribution_course_overrides',
                'share_visits',
                'share_entries',
            ] as $table
        ) {
            $this->assertStringContainsString(
                'drop()->save();',
                $this->migrationSource,
                'down() must drop ' . $table,
            );
        }
    }
}