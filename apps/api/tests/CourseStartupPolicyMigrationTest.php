<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class CourseStartupPolicyMigrationTest extends TestCase
{
    public function testMigrationDefinesCoursePolicyDefaultsAndRollback(): void
    {
        $path = dirname(__DIR__) . '/database/migrations/20260912000001_add_course_startup_policy.php';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);

        foreach ([
            "table('courses'",
            "addColumn('idle_threshold_hours'",
            "addColumn('reminder_frequency_hours'",
            "addColumn('reminder_cap'",
            "'default' => 72",
            "'default' => 3",
            'function down',
            'removeColumn',
        ] as $needle) {
            self::assertStringContainsString($needle, $source);
        }
    }
}
