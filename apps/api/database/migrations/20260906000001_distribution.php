<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 016-course-distribution — five tables, one column extension, one row in
 * site_settings (key='distribution_config'). Hard guards against the SC-003
 * compliance ceiling are layered here in MySQL so a bypass of the app layer
 * still trips CHECK and trigger constraints:
 *   1. commission_records.level BETWEEN 1 AND 3 (CHECK)
 *   2. commission_records UNIQUE (order_id, referrer_learner_id) — single
 *      settlement never pays the same referrer twice
 *   3. learners.referrer_learner_id self-FK + trigger blocking UPDATE so
 *      FR-001/FR-002 ("写入即不可改") cannot be defeated via direct SQL
 *   4. site_settings CHECK JSON_EXTRACT(value,'$.level_cap') <= 3 — same
 *      ceiling enforced on the config blob
 *
 * ponytail: this file is the only place a new table for 016 lands. Adding
 * a future column means another migration; do not co-locate here.
 */
final class Distribution extends AbstractMigration
{
    public function up(): void
    {
        // 1) learners — extend with referrer_learner_id (nullable, immutable)
        if ($this->hasTable('learners')) {
            $learners = $this->table('learners');
            if (!$learners->hasColumn('referrer_learner_id')) {
                $learners
                    ->addColumn('referrer_learner_id', 'biginteger', [
                        'signed' => false,
                        'null' => true,
                        'after' => 'account_id',
                    ])
                    ->addIndex(['referrer_learner_id'], ['name' => 'idx_learners_referrer'])
                    ->update();
                // Self-FK so referrer must reference an existing learner
                // (NULL allowed for legacy / imported accounts).
                $this->execute(
                    'ALTER TABLE learners
                       ADD CONSTRAINT fk_learners_referrer
                       FOREIGN KEY (referrer_learner_id)
                       REFERENCES learners (account_id)
                       ON DELETE RESTRICT ON UPDATE CASCADE'
                );
            }
        }

        // 2) share_entries — learner-owned share links
        if (!$this->hasTable('share_entries')) {
            $this->table('share_entries', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('learner_id', 'biginteger', ['signed' => false])
                ->addColumn('scope', 'enum', ['values' => ['course', 'site']])
                ->addColumn('course_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('short_code', 'string', ['limit' => 64])
                ->addColumn('distribution_enabled_at_creation', 'boolean', ['default' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('revoked_at', 'datetime', ['null' => true])
                ->addIndex(['short_code'], ['unique' => true, 'name' => 'uk_share_short_code'])
                ->addIndex(['learner_id', 'scope', 'course_id'], ['name' => 'idx_share_owner'])
                ->addIndex(['revoked_at'], ['name' => 'idx_share_revoked'])
                ->create();
        }

        // 3) share_visits — anonymous click log + visitor_token binding
        if (!$this->hasTable('share_visits')) {
            $this->table('share_visits', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('share_entry_id', 'biginteger', ['signed' => false])
                ->addColumn('visitor_token', 'string', ['limit' => 64])
                ->addColumn('ip', 'string', ['limit' => 45, 'null' => true])
                ->addColumn('user_agent', 'string', ['limit' => 255, 'null' => true])
                ->addColumn('bound_learner_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('bound_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['share_entry_id', 'visitor_token'], ['name' => 'idx_visit_entry_token'])
                ->addIndex(['visitor_token', 'bound_at'], ['name' => 'idx_visit_token_bound'])
                ->addIndex(['bound_learner_id'], ['name' => 'idx_visit_bound'])
                ->create();
        }

        // 4) distribution_course_overrides — per-course opt in/out + ratios
        if (!$this->hasTable('distribution_course_overrides')) {
            $this->table('distribution_course_overrides', [
                'id' => false,
                'primary_key' => ['course_id'],
            ])
                ->addColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('level1_pct', 'decimal', ['precision' => 6, 'scale' => 4, 'null' => true])
                ->addColumn('level2_pct', 'decimal', ['precision' => 6, 'scale' => 4, 'null' => true])
                ->addColumn('level3_pct', 'decimal', ['precision' => 6, 'scale' => 4, 'null' => true])
                ->addColumn('per_order_cap_cents', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('per_learner_course_cap_cents', 'integer', ['signed' => false, 'null' => true])
                ->addColumn('updated_by_staff_id', 'biginteger', ['signed' => false])
                ->addColumn('updated_at', 'datetime')
                ->create();
        }

        // 5) commission_records — one row per (order, referrer, level)
        if (!$this->hasTable('commission_records')) {
            $this->table('commission_records', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('order_id', 'biginteger', ['signed' => false])
                ->addColumn('course_id', 'biginteger', ['signed' => false])
                ->addColumn('referee_learner_id', 'biginteger', ['signed' => false])
                ->addColumn('referrer_learner_id', 'biginteger', ['signed' => false])
                ->addColumn('level', 'tinyinteger', ['signed' => false])
                ->addColumn('amount_cents', 'integer', ['signed' => false])
                ->addColumn('status', 'enum', [
                    'values' => ['pending', 'settled', 'voided', 'pending_blocked'],
                    'default' => 'pending',
                ])
                ->addColumn('source', 'enum', [
                    'values' => ['system_settle', 'system_refund_void', 'admin_void'],
                    'default' => 'system_settle',
                ])
                ->addColumn('config_snapshot_json', 'text')
                ->addColumn('order_paid_cents_snapshot', 'integer', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addColumn('settled_at', 'datetime', ['null' => true])
                ->addColumn('voided_at', 'datetime', ['null' => true])
                ->addColumn('void_reason', 'string', ['limit' => 500, 'null' => true])
                ->addIndex(['order_id', 'referrer_learner_id'], [
                    'unique' => true,
                    'name' => 'uk_commission_order_referrer',
                ])
                ->addIndex(['referrer_learner_id', 'status'], ['name' => 'idx_commission_referrer_status'])
                ->addIndex(['order_id'], ['name' => 'idx_commission_order'])
                ->addIndex(['course_id', 'referrer_learner_id'], ['name' => 'idx_commission_course_referrer'])
                ->create();
            // CHECK guards the SC-003 ceiling: level must be in {1,2,3}.
            $this->execute(
                'ALTER TABLE commission_records
                   ADD CONSTRAINT chk_commission_level
                   CHECK (level BETWEEN 1 AND 3)'
            );
            // CHECK guards amount: non-negative and non-null.
            $this->execute(
                'ALTER TABLE commission_records
                   ADD CONSTRAINT chk_commission_amount
                   CHECK (amount_cents >= 0)'
            );
        }

        // 6) distribution_audit_log — append-only, single writer.
        if (!$this->hasTable('distribution_audit_log')) {
            $this->table('distribution_audit_log', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('actor_type', 'enum', ['values' => ['admin', 'system']])
                ->addColumn('actor_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('action', 'string', ['limit' => 64])
                ->addColumn('subject_type', 'string', ['limit' => 32])
                ->addColumn('subject_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('before_json', 'text', ['null' => true])
                ->addColumn('after_json', 'text', ['null' => true])
                ->addColumn('reason', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('created_at', 'datetime')
                ->addIndex(['action', 'created_at'], ['name' => 'idx_audit_action_time'])
                ->addIndex(['subject_type', 'subject_id'], ['name' => 'idx_audit_subject'])
                ->addIndex(['actor_type', 'actor_id'], ['name' => 'idx_audit_actor'])
                ->create();
        }

        // 7) site_settings — single-row config for distribution.
        // CHECK level_cap <= 3 covers the SC-003 compliance ceiling at the
        // storage layer; an admin bypassing the service must still trip it.
        if (!$this->hasTable('site_settings')) {
            $this->table('site_settings', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('key', 'string', ['limit' => 64])
                ->addColumn('value', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_REGULAR])
                ->addColumn('updated_at', 'datetime')
                ->addIndex(['key'], ['unique' => true, 'name' => 'uk_site_settings_key'])
                ->create();
        }
        $exists = $this->fetchRow(
            "SELECT 1 FROM site_settings WHERE `key` = 'distribution_config' LIMIT 1"
        );
        if ($exists === null) {
            $this->execute(
                "INSERT INTO site_settings (`key`, `value`, `updated_at`)
                 VALUES (
                   'distribution_config',
                   JSON_OBJECT(
                     'enabled', false,
                     'level_cap', 3,
                     'level1_pct', 0.10,
                     'level2_pct', 0.05,
                     'level3_pct', 0.02,
                     'base', 'order_paid',
                     'per_order_cap_cents', 5000,
                     'per_learner_course_cap_cents', 50000,
                     'per_learner_total_cap_cents', NULL,
                     'settlement', 'order_settled',
                     'refund_void_rule', 'void_all',
                     'payout_form', 'cash_record_only',
                     'learner_can_view_detail', true,
                     'updated_by', 1
                   ),
                   NOW()
                 )"
            );
        }
        // Idempotent guard: re-running this migration must not trip MySQL
        // 1061 "Duplicate key name". down() drops the constraint symmetrically.
        $siteSettings = $this->table('site_settings');
        $hasLevelCapConstraint = method_exists($siteSettings, 'hasCheckConstraint')
            ? $siteSettings->hasCheckConstraint('chk_distribution_level_cap')
            : $this->fetchRow(
                "SELECT 1
                 FROM information_schema.TABLE_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE()
                   AND TABLE_NAME = 'site_settings'
                   AND CONSTRAINT_NAME = 'chk_distribution_level_cap'
                   AND CONSTRAINT_TYPE = 'CHECK'
                 LIMIT 1"
            ) !== false;
        if (!$hasLevelCapConstraint) {
            $this->execute(
                "ALTER TABLE site_settings
                   ADD CONSTRAINT chk_distribution_level_cap
                   CHECK (
                     `key` <> 'distribution_config'
                     OR CAST(JSON_EXTRACT(`value`, '$.level_cap') AS UNSIGNED) <= 3
                   )"
            );
        }

        // 8) Trigger: once referrer_learner_id is set, no UPDATE may touch it.
        // FR-001 / FR-002 — "推荐关系写入即不可改" — must hold at the DB layer.
        $this->execute('DROP TRIGGER IF EXISTS trg_learners_referrer_immutable_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_learners_referrer_immutable_delete');
        $this->execute(
            'CREATE TRIGGER trg_learners_referrer_immutable_update
               BEFORE UPDATE ON learners
               FOR EACH ROW
               BEGIN
                 IF OLD.referrer_learner_id IS NOT NULL
                    AND (NEW.referrer_learner_id <> OLD.referrer_learner_id
                         OR NEW.referrer_learner_id IS NULL) THEN
                   SIGNAL SQLSTATE \'45000\'
                     SET MESSAGE_TEXT = \'learners.referrer_learner_id is immutable once set\';
                 END IF;
               END'
        );
        $this->execute(
            'CREATE TRIGGER trg_learners_referrer_immutable_delete
               BEFORE DELETE ON learners
               FOR EACH ROW
               BEGIN
                 IF OLD.referrer_learner_id IS NOT NULL THEN
                   SIGNAL SQLSTATE \'45000\'
                     SET MESSAGE_TEXT = \'learners with a referrer cannot be hard-deleted\';
                 END IF;
               END'
        );
    }

    public function down(): void
    {
        $this->execute('DROP TRIGGER IF EXISTS trg_learners_referrer_immutable_update');
        $this->execute('DROP TRIGGER IF EXISTS trg_learners_referrer_immutable_delete');

        $tables = [
            'distribution_audit_log',
            'commission_records',
            'distribution_course_overrides',
            'share_visits',
            'share_entries',
        ];
        foreach ($tables as $table) {
            if ($this->hasTable($table)) {
                $this->table($table)->drop()->save();
            }
        }

        if ($this->hasTable('site_settings')) {
            $this->execute(
                "DELETE FROM site_settings WHERE `key` = 'distribution_config'"
            );
            $this->execute(
                'ALTER TABLE site_settings DROP CONSTRAINT IF EXISTS chk_distribution_level_cap'
            );
        }

        if ($this->hasTable('learners')) {
            $learners = $this->table('learners');
            if ($learners->hasForeignKey('fk_learners_referrer')) {
                $this->execute(
                    'ALTER TABLE learners DROP FOREIGN KEY fk_learners_referrer'
                );
            }
            if ($learners->hasIndex('idx_learners_referrer')) {
                $learners->removeIndex('idx_learners_referrer')->update();
            }
            if ($learners->hasColumn('referrer_learner_id')) {
                $learners->removeColumn('referrer_learner_id')->update();
            }
        }
    }
}
