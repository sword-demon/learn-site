<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Forward-only follow-up to 20260906000001_distribution.
 *
 * The original migration already ran in deployed environments. Later schema
 * intent (settlement after refund window, level_cap BETWEEN 1 AND 3) must
 * not edit that file in place.
 */
final class DistributionSettlementAndLevelCap extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }
        $this->execute(
            "UPDATE site_settings
                SET `value` = JSON_SET(`value`, '$.settlement', 'order_settled_after_refund_window')
              WHERE `key` = 'distribution_config'
                AND JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.settlement')) = 'order_settled'"
        );
        $this->replaceLevelCapCheck(
            'CAST(JSON_EXTRACT(`value`, \'$.level_cap\') AS UNSIGNED) BETWEEN 1 AND 3'
        );
    }

    public function down(): void
    {
        if (!$this->hasTable('site_settings')) {
            return;
        }
        $this->execute(
            "UPDATE site_settings
                SET `value` = JSON_SET(`value`, '$.settlement', 'order_settled')
              WHERE `key` = 'distribution_config'
                AND JSON_UNQUOTE(JSON_EXTRACT(`value`, '$.settlement')) = 'order_settled_after_refund_window'"
        );
        $this->replaceLevelCapCheck(
            'CAST(JSON_EXTRACT(`value`, \'$.level_cap\') AS UNSIGNED) <= 3'
        );
    }

    private function replaceLevelCapCheck(string $levelCapPredicate): void
    {
        $exists = $this->fetchRow(
            "SELECT 1
               FROM information_schema.TABLE_CONSTRAINTS
              WHERE CONSTRAINT_SCHEMA = DATABASE()
                AND TABLE_NAME = 'site_settings'
                AND CONSTRAINT_NAME = 'chk_distribution_level_cap'
                AND CONSTRAINT_TYPE = 'CHECK'
              LIMIT 1"
        );
        if ($exists !== null && $exists !== false) {
            $this->execute('ALTER TABLE site_settings DROP CHECK chk_distribution_level_cap');
        }
        $this->execute(
            "ALTER TABLE site_settings
               ADD CONSTRAINT chk_distribution_level_cap
               CHECK (
                 `key` <> 'distribution_config'
                 OR {$levelCapPredicate}
               )"
        );
    }
}
