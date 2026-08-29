<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class HardenLearnerNotifications extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('learner_notifications');
        if (!$table->hasColumn('resource_type')) {
            $table->addColumn('resource_type', 'string', ['limit' => 32, 'null' => true]);
        }
        if (!$table->hasColumn('resource_id')) {
            $table->addColumn('resource_id', 'biginteger', ['signed' => false, 'null' => true]);
        }
        if (!$table->hasColumn('idempotency_key')) {
            $table->addColumn('idempotency_key', 'string', ['limit' => 191, 'null' => true]);
        }
        $table->update();

        $table = $this->table('learner_notifications');
        if (!$table->hasIndex(['resource_type', 'resource_id'])) {
            $table->addIndex(['resource_type', 'resource_id']);
        }
        if (!$table->hasIndex(['learner_id', 'idempotency_key'])) {
            $table->addIndex(['learner_id', 'idempotency_key'], ['unique' => true]);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('learner_notifications');
        if ($table->hasIndex(['learner_id', 'idempotency_key'])) {
            $table->removeIndex(['learner_id', 'idempotency_key']);
        }
        if ($table->hasIndex(['resource_type', 'resource_id'])) {
            $table->removeIndex(['resource_type', 'resource_id']);
        }
        foreach (['idempotency_key', 'resource_id', 'resource_type'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
