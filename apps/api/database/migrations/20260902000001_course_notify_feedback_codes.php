<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * 010-course-notify-feedback-codes — publish notification fan-out resources,
 * per-course activation codes, and private course feedback.
 *
 * Tables:
 *  - activation_code_batches:管理员一次生成操作(数量、可选过期时间、生成者)。
 *  - activation_codes:一码一课一次性兑换凭证,SHA-256 哈希入库,明文不落库。
 *  - course_feedbacks:持访问权学员的私密富文本意见,与 reviews 零共享。
 * Extensions:
 *  - notification_dispatches.type + course_published,新增 resource_type/resource_id。
 *  - course_entitlements.source + activation_code,新增可空 activation_code_id。
 *
 * 设计依据:specs/010-course-notify-feedback-codes/{data-model,research}.md。
 *
 * Each table is gated on hasTable so the migration is idempotent and a
 * half-applied run can resume without dropping partial state. ENUM 扩展
 * 显式携带旧值+新值(见 plan 风险表:已有库 ENUM 变更必须 MODIFY 全量枚举)。
 */
final class CourseNotifyFeedbackCodes extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('activation_code_batches')) {
            $this->table('activation_code_batches', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('course_id', 'biginteger', ['signed' => false])
                ->addColumn('quantity', 'integer', ['signed' => false])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('created_by_staff_id', 'biginteger', ['signed' => false])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('created_by_staff_id', 'staff_users', 'account_id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addIndex(['course_id', 'created_at'], ['name' => 'idx_activation_batch_course'])
                ->create();
        }

        if (!$this->hasTable('activation_codes')) {
            $this->table('activation_codes', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('batch_id', 'biginteger', ['signed' => false])
                ->addColumn('course_id', 'biginteger', ['signed' => false])
                ->addColumn('code_hash', 'char', ['limit' => 64])
                ->addColumn('code_prefix', 'char', ['limit' => 4])
                ->addColumn('code_suffix', 'char', ['limit' => 4])
                ->addColumn('status', 'enum', [
                    'values' => ['unused', 'redeemed', 'void'],
                    'default' => 'unused',
                ])
                ->addColumn('expires_at', 'datetime', ['null' => true])
                ->addColumn('redeemed_by_learner_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('redeemed_at', 'datetime', ['null' => true])
                ->addColumn('voided_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('voided_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addForeignKey('batch_id', 'activation_code_batches', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('redeemed_by_learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('voided_by_staff_id', 'staff_users', 'account_id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addIndex(['code_hash'], ['unique' => true, 'name' => 'uniq_activation_codes_hash'])
                ->addIndex(['course_id', 'status'], ['name' => 'idx_activation_codes_course_status'])
                ->addIndex(['batch_id'], ['name' => 'idx_activation_codes_batch'])
                ->addIndex(['redeemed_by_learner_id'], ['name' => 'idx_activation_codes_redeemer'])
                ->create();
        }

        if (!$this->hasTable('course_feedbacks')) {
            $this->table('course_feedbacks', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('course_id', 'biginteger', ['signed' => false])
                ->addColumn('learner_id', 'biginteger', ['signed' => false])
                ->addColumn('body_html', 'text', ['limit' => MysqlAdapter::TEXT_MEDIUM])
                ->addColumn('status', 'enum', ['values' => ['pending', 'processed'], 'default' => 'pending'])
                ->addColumn('processed_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('processed_at', 'datetime', ['null' => true])
                ->addColumn('created_at', 'datetime')
                ->addColumn('updated_at', 'datetime')
                ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
                ->addIndex(['course_id', 'created_at'], ['name' => 'idx_course_feedbacks_course_created'])
                ->addIndex(['course_id', 'status'], ['name' => 'idx_course_feedbacks_course_status'])
                ->addIndex(['learner_id', 'created_at'], ['name' => 'idx_course_feedbacks_learner'])
                ->create();
        }

        $dispatches = $this->table('notification_dispatches');
        if (!$dispatches->hasColumn('resource_type')) {
            $dispatches->addColumn('resource_type', 'string', ['limit' => 32, 'null' => true, 'after' => 'body']);
        }
        if (!$dispatches->hasColumn('resource_id')) {
            $dispatches->addColumn('resource_id', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'resource_type']);
        }
        if (!$dispatches->hasColumn('recipient_snapshot_max_id')) {
            $dispatches->addColumn('recipient_snapshot_max_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'after' => 'recipient_count',
            ]);
        }
        if (!$dispatches->hasIndex(['resource_type', 'resource_id'])) {
            $dispatches->addIndex(['resource_type', 'resource_id'], ['name' => 'idx_notification_dispatches_resource']);
        }
        $dispatches->update();

        $entitlements = $this->table('course_entitlements');
        if (!$entitlements->hasColumn('activation_code_id')) {
            $entitlements->addColumn('activation_code_id', 'biginteger', ['signed' => false, 'null' => true, 'after' => 'order_id']);
        }
        if (!$entitlements->hasIndex(['activation_code_id'])) {
            $entitlements->addIndex(['activation_code_id'], [
                'unique' => true,
                'name' => 'uniq_course_entitlements_activation_code',
            ]);
        }
        if (!$entitlements->hasForeignKey('activation_code_id')) {
            $entitlements->addForeignKey('activation_code_id', 'activation_codes', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'CASCADE',
                'constraint' => 'fk_course_entitlements_activation_code',
            ]);
        }
        $entitlements->update();
        if ($this->isUsingMysql()) {
            // MODIFY 必须显式携带旧值+新值;ENUM 变更在已有库上不能丢历史枚举。
            $this->execute(
                'ALTER TABLE notification_dispatches MODIFY type '
                . "ENUM('announcement','internal_message','course_published') NOT NULL",
            );
            $this->execute(
                'ALTER TABLE course_entitlements MODIFY source '
                . "ENUM('free','purchase','activation_code') NOT NULL",
            );
        }
    }

    public function down(): void
    {
        $usageChecks = [
            "SELECT COUNT(*) AS aggregate FROM course_entitlements WHERE source = 'activation_code'",
            "SELECT COUNT(*) AS aggregate FROM notification_dispatches WHERE type = 'course_published'",
            'SELECT COUNT(*) AS aggregate FROM activation_codes',
            'SELECT COUNT(*) AS aggregate FROM course_feedbacks',
        ];
        foreach ($usageChecks as $sql) {
            $row = $this->fetchRow($sql);
            if ((int) ($row['aggregate'] ?? 0) > 0) {
                throw new RuntimeException(
                    '010 rollback refused: activation-code, feedback, or course-published data exists; '
                    . 'export/compensate the business data before removing this schema.',
                );
            }
        }

        if ($this->hasTable('course_entitlements') && $this->table('course_entitlements')->hasColumn('activation_code_id')) {
            $entitlements = $this->table('course_entitlements');
            if ($entitlements->hasForeignKey('activation_code_id')) {
                $entitlements->dropForeignKey('activation_code_id');
            }
            $entitlements->removeColumn('activation_code_id');
            $entitlements->update();
        }
        if ($this->isUsingMysql()) {
            // down 同样携带旧值+新值语义;前置检查已确保无新枚举数据。
            $this->execute(
                'ALTER TABLE course_entitlements MODIFY source '
                . "ENUM('free','purchase') NOT NULL",
            );
            $this->execute(
                'ALTER TABLE notification_dispatches MODIFY type '
                . "ENUM('announcement','internal_message') NOT NULL",
            );
        }

        $dispatches = $this->table('notification_dispatches');
        if ($dispatches->hasIndex(['resource_type', 'resource_id'])) {
            $dispatches->removeIndex(['resource_type', 'resource_id']);
        }
        $dispatches->update();
        if ($dispatches->hasColumn('resource_id')) {
            $dispatches->removeColumn('resource_id');
        }
        if ($dispatches->hasColumn('resource_type')) {
            $dispatches->removeColumn('resource_type');
        }
        if ($dispatches->hasColumn('recipient_snapshot_max_id')) {
            $dispatches->removeColumn('recipient_snapshot_max_id');
        }
        $dispatches->update();

        if ($this->hasTable('course_feedbacks')) {
            $this->table('course_feedbacks')->drop()->save();
        }
        if ($this->hasTable('activation_codes')) {
            $this->table('activation_codes')->drop()->save();
        }
        if ($this->hasTable('activation_code_batches')) {
            $this->table('activation_code_batches')->drop()->save();
        }
    }

    private function isUsingMysql(): bool
    {
        return $this->getAdapter()->getAdapterType() === 'mysql';
    }
}
