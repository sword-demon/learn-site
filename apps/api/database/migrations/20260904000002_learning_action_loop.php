<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class LearningActionLoop extends AbstractMigration
{
    public function up(): void
    {
        // Older installs started with enum columns; notification kinds and
        // resources are intentionally open strings for forward-compatible
        // message/resource additions.
        if ($this->hasTable('learner_notifications')) {
            $this->execute('ALTER TABLE learner_notifications MODIFY kind VARCHAR(32) NOT NULL, MODIFY resource_type VARCHAR(32) NULL');
        }
        if ($this->hasTable('learner_reminder_evaluations')) {
            return;
        }

        $this->table('learner_reminder_evaluations', [
            'id' => false,
            'primary_key' => ['id'],
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('rule_code', 'string', ['limit' => 48])
            ->addColumn('candidate_key', 'string', ['limit' => 160])
            ->addColumn('resource_type', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('resource_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('evaluation_day', 'date')
            ->addColumn('evaluation_status', 'enum', [
                'values' => [
                    'not_eligible',
                    'quiet_hours',
                    'daily_cap',
                    'throttled',
                    'resource_unavailable',
                    'sent',
                    'failed',
                ],
            ])
            ->addColumn('reason_code', 'string', ['limit' => 64])
            ->addColumn('last_evaluated_at', 'datetime')
            ->addColumn('first_sent_at', 'datetime', ['null' => true])
            ->addColumn('last_sent_at', 'datetime', ['null' => true])
            ->addColumn('send_count', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('notification_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('suppressed_at', 'datetime', ['null' => true])
            ->addColumn('error_message', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['learner_id', 'rule_code', 'candidate_key'], [
                'unique' => true,
                'name' => 'uq_learner_reminder_event',
            ])
            ->addIndex(['learner_id', 'last_sent_at'], ['name' => 'idx_reminder_learner_sent'])
            ->addIndex(['rule_code', 'last_evaluated_at'], ['name' => 'idx_reminder_rule_evaluated'])
            ->addIndex(['notification_id'], ['name' => 'idx_reminder_notification'])
            ->addForeignKey('learner_id', 'accounts', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_learner_reminder_evaluations_learner',
            ])
            ->addForeignKey('notification_id', 'learner_notifications', 'id', [
                'delete' => 'SET_NULL',
                'update' => 'RESTRICT',
                'constraint' => 'fk_learner_reminder_evaluations_notification',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('learner_reminder_evaluations')) {
            $this->table('learner_reminder_evaluations')->drop()->save();
        }
    }
}
