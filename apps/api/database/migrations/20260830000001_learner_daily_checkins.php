<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 005-learner-daily-checkin — learner daily check-in records.
 */
final class LearnerDailyCheckins extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('learner_daily_checkins')) {
            return;
        }

        $this->table('learner_daily_checkins', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('checkin_date', 'date')
            ->addColumn('plan_html', 'text', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_REGULAR,
            ])
            ->addColumn('checked_in_at', 'datetime')
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['learner_id', 'checkin_date'], ['unique' => true, 'name' => 'uk_learner_checkin_date'])
            ->addIndex(['checkin_date'])
            ->addIndex(['checked_in_at'])
            ->addForeignKey('learner_id', 'accounts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_learner_daily_checkins_learner',
            ])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('learner_daily_checkins')) {
            $this->table('learner_daily_checkins')->drop()->save();
        }
    }
}
