<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class LearnerReminderEvaluationMigrationTest extends TestCase
{
    public function testMigrationDefinesTheEvaluationTableContractAndRollback(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/20260904000002_learning_action_loop.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            "table('learner_reminder_evaluations'",
            "addColumn('learner_id'",
            "addColumn('rule_code'",
            "addColumn('candidate_key'",
            "addColumn('evaluation_status'",
            "addColumn('last_sent_at'",
            "addColumn('suppressed_at'",
            "addColumn('notification_id'",
            "['learner_id', 'rule_code', 'candidate_key']",
            'evaluation_status',
            'fk_learner_reminder_evaluations_learner',
            'fk_learner_reminder_evaluations_notification',
            'function down',
            'drop',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }
}
