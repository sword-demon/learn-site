<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class QaSchemaIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    public function testQuestionIdentityAndContentColumnsAreRequired(): void
    {
        $columns = $this->columnsFor('questions');
        foreach (
            [
                'course_id',
                'chapter_id',
                'lesson_id',
                'learner_id',
                'title',
                'body',
                'status',
                'created_at',
                'updated_at',
            ] as $column
        ) {
            $this->assertSame('NO', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
        $this->assertSame('pending', $columns['status']['COLUMN_DEFAULT'] ?? null);
    }

    public function testMessageThreadColumnsAreRequired(): void
    {
        $columns = $this->columnsFor('question_messages');
        foreach (['question_id', 'kind', 'body', 'created_at'] as $column) {
            $this->assertSame('NO', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
    }

    public function testQuestionForeignKeysPreserveBindingAndAnswerAttribution(): void
    {
        $foreignKeys = $this->foreignKeysFor('questions');
        $this->assertSame(
            ['courses', 'id', 'CASCADE', 'CASCADE'],
            $foreignKeys['course_id'] ?? null,
        );
        $this->assertSame(
            ['chapters', 'id', 'CASCADE', 'CASCADE'],
            $foreignKeys['chapter_id'] ?? null,
        );
        $this->assertSame(
            ['lessons', 'id', 'CASCADE', 'CASCADE'],
            $foreignKeys['lesson_id'] ?? null,
        );
        $this->assertSame(
            ['accounts', 'id', 'RESTRICT', 'CASCADE'],
            $foreignKeys['learner_id'] ?? null,
        );
        $this->assertSame(
            ['staff_users', 'account_id', 'SET NULL', 'CASCADE'],
            $foreignKeys['answered_by_staff_id'] ?? null,
        );
    }

    public function testIndexesMatchInboxLessonAndThreadQueries(): void
    {
        $questionIndexes = array_values($this->indexesFor('questions'));
        $messageIndexes = array_values($this->indexesFor('question_messages'));

        $this->assertContains('status,id', $questionIndexes);
        $this->assertContains('lesson_id,id', $questionIndexes);
        $this->assertContains('question_id,id', $messageIndexes);
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsFor(string $table): array
    {
        $rows = Db::query(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_DEFAULT
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
            [$table],
        );

        $columns = [];
        foreach ($rows as $row) {
            $columns[(string) $row['COLUMN_NAME']] = $row;
        }
        return $columns;
    }

    /** @return array<string, array{string, string, string, string}> */
    private function foreignKeysFor(string $table): array
    {
        $rows = Db::query(
            'SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL',
            [$table],
        );

        $foreignKeys = [];
        foreach ($rows as $row) {
            $foreignKeys[(string) $row['COLUMN_NAME']] = [
                (string) $row['REFERENCED_TABLE_NAME'],
                (string) $row['REFERENCED_COLUMN_NAME'],
                (string) $row['DELETE_RULE'],
                (string) $row['UPDATE_RULE'],
            ];
        }
        return $foreignKeys;
    }

    /** @return array<string, string> */
    private function indexesFor(string $table): array
    {
        $rows = Db::query(
            "SELECT INDEX_NAME,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS INDEX_COLUMNS
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             GROUP BY INDEX_NAME",
            [$table],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row['INDEX_NAME']] = (string) $row['INDEX_COLUMNS'];
        }
        return $indexes;
    }
}
