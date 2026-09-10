<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ContentTodoSchemaIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    public function testContentTodoColumnsAndDefaultsMatchWorkflowContract(): void
    {
        $columns = $this->columnsFor('content_todos');

        foreach ([
            'source_type',
            'source_key',
            'workflow_status',
            'version',
            'created_at',
            'updated_at',
        ] as $column) {
            self::assertSame('NO', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
        self::assertSame('untriaged', $columns['workflow_status']['COLUMN_DEFAULT'] ?? null);
        self::assertSame('1', (string) ($columns['version']['COLUMN_DEFAULT'] ?? null));
        self::assertSame('YES', $columns['target_lesson_id']['IS_NULLABLE'] ?? null);
        self::assertSame('YES', $columns['first_response_notification_id']['IS_NULLABLE'] ?? null);
    }

    public function testCandidateColumnsKeepApprovalHistoryAndNullableTargets(): void
    {
        $columns = $this->columnsFor('content_todo_candidates');

        foreach ([
            'content_todo_id',
            'version',
            'target_kind',
            'body',
            'body_format',
            'base_content_fingerprint',
            'status',
            'generated_at',
            'created_at',
            'updated_at',
        ] as $column) {
            self::assertSame('NO', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
        foreach (['target_course_id', 'target_chapter_id', 'target_lesson_id', 'approved_at', 'rejected_at'] as $column) {
            self::assertSame('YES', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
        self::assertSame('draft', $columns['status']['COLUMN_DEFAULT'] ?? null);
    }

    public function testSourceAndCandidateVersionsAreUniqueAndIndexedForInboxQueries(): void
    {
        $todoIndexes = $this->indexesFor('content_todos');
        $candidateIndexes = $this->indexesFor('content_todo_candidates');

        self::assertSame(
            ['columns' => 'source_type,source_key', 'unique' => true],
            $todoIndexes['uk_content_todos_source'] ?? null,
        );
        self::assertSame(
            ['columns' => 'content_todo_id,version', 'unique' => true],
            $candidateIndexes['uk_content_todo_candidate_version'] ?? null,
        );
        self::assertArrayHasKey('idx_content_todos_scope_status', $todoIndexes);
        self::assertArrayHasKey('idx_content_todos_target', $todoIndexes);
        self::assertArrayHasKey('idx_content_todo_candidate_status', $candidateIndexes);
    }

    public function testHistoricalReferencesDoNotBlockCourseOrStaffDeletion(): void
    {
        $todoForeignKeys = $this->foreignKeysFor('content_todos');
        $candidateForeignKeys = $this->foreignKeysFor('content_todo_candidates');

        self::assertSame(
            ['courses', 'id', 'SET NULL', 'CASCADE'],
            $todoForeignKeys['source_course_id'] ?? null,
        );
        self::assertSame(
            ['courses', 'id', 'SET NULL', 'CASCADE'],
            $candidateForeignKeys['target_course_id'] ?? null,
        );
        self::assertSame(
            ['staff_users', 'account_id', 'SET NULL', 'CASCADE'],
            $candidateForeignKeys['approved_by_staff_id'] ?? null,
        );
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

    /** @return array<string, array{columns:string,unique:bool}> */
    private function indexesFor(string $table): array
    {
        $rows = Db::query(
            "SELECT INDEX_NAME, NON_UNIQUE,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS INDEX_COLUMNS
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
             GROUP BY INDEX_NAME, NON_UNIQUE",
            [$table],
        );

        $indexes = [];
        foreach ($rows as $row) {
            $indexes[(string) $row['INDEX_NAME']] = [
                'columns' => (string) $row['INDEX_COLUMNS'],
                'unique' => (int) $row['NON_UNIQUE'] === 0,
            ];
        }
        return $indexes;
    }

    /** @return array<string, array{string,string,string,string}> */
    private function foreignKeysFor(string $table): array
    {
        $rows = Db::query(
            "SELECT kcu.COLUMN_NAME, kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL",
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
}
