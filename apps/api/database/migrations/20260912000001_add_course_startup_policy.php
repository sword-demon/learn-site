<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Course-owned startup queue policy.
 *
 * Existing courses receive these documented defaults without backfilling
 * entitlements or learning records:
 *   idle_threshold_hours     = 72
 *   reminder_frequency_hours = 72
 *   reminder_cap             = 3
 */
final class AddCourseStartupPolicy extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('courses');
        if (!$table->hasColumn('idle_threshold_hours')) {
            $table->addColumn('idle_threshold_hours', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 72,
                'after' => 'sale_end_at',
            ]);
        }
        if (!$table->hasColumn('reminder_frequency_hours')) {
            $table->addColumn('reminder_frequency_hours', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 72,
                'after' => 'idle_threshold_hours',
            ]);
        }
        if (!$table->hasColumn('reminder_cap')) {
            $table->addColumn('reminder_cap', 'integer', [
                'signed' => false,
                'null' => false,
                'default' => 3,
                'after' => 'reminder_frequency_hours',
            ]);
        }
        $table->update();
    }

    public function down(): void
    {
        $table = $this->table('courses');
        foreach (['reminder_cap', 'reminder_frequency_hours', 'idle_threshold_hours'] as $column) {
            if ($table->hasColumn($column)) {
                $table->removeColumn($column);
            }
        }
        $table->update();
    }
}
