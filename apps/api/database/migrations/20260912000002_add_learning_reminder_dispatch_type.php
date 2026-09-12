<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Extend notification_dispatches.type with course-start learning reminders.
 *
 * Existing ENUM values must be restated; MySQL cannot drop historical values
 * during MODIFY.
 */
final class AddLearningReminderDispatchType extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('notification_dispatches') || !$this->isUsingMysql()) {
            return;
        }
        $this->execute(
            'ALTER TABLE notification_dispatches MODIFY type '
            . "ENUM('announcement','internal_message','course_published','learning_reminder') NOT NULL",
        );
    }

    public function down(): void
    {
        if (!$this->hasTable('notification_dispatches') || !$this->isUsingMysql()) {
            return;
        }
        $row = $this->fetchRow(
            "SELECT COUNT(*) AS aggregate FROM notification_dispatches WHERE type = 'learning_reminder'",
        );
        if ((int) ($row['aggregate'] ?? 0) > 0) {
            throw new RuntimeException(
                'Rollback refused: learning_reminder dispatches exist; export or delete them first.',
            );
        }
        $this->execute(
            'ALTER TABLE notification_dispatches MODIFY type '
            . "ENUM('announcement','internal_message','course_published') NOT NULL",
        );
    }

    private function isUsingMysql(): bool
    {
        return $this->getAdapter()->getAdapterType() === 'mysql';
    }
}
