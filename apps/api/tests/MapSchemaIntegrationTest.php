<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class MapSchemaIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    public function testMapMetadataSupportsCoverObjectiveAndAudience(): void
    {
        $columns = $this->columnsFor('learning_maps');

        $this->assertSame('varchar(255)', $columns['cover_url']['COLUMN_TYPE'] ?? null);
        $this->assertSame('text', $columns['objective']['COLUMN_TYPE'] ?? null);
        $this->assertSame('text', $columns['audience']['COLUMN_TYPE'] ?? null);
        foreach (['cover_url', 'objective', 'audience'] as $column) {
            $this->assertSame('YES', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
    }

    public function testCourseIsUniqueAcrossAllStagesInOneMap(): void
    {
        $columns = $this->columnsFor('map_stage_courses');
        $indexes = $this->indexesFor('map_stage_courses');

        $this->assertSame('NO', $columns['map_id']['IS_NULLABLE'] ?? null);
        $this->assertSame(
            ['columns' => 'map_id,course_id', 'unique' => true],
            $indexes['uq_map_course'] ?? null,
        );
    }

    public function testStepForeignKeysPreserveMapTopologyAndCourseHistory(): void
    {
        $foreignKeys = $this->foreignKeysFor('map_stage_courses');

        $this->assertSame(
            ['map_stages', 'id,map_id', 'CASCADE', 'RESTRICT'],
            $foreignKeys['stage_id,map_id'] ?? null,
        );
        $this->assertSame(
            ['courses', 'id', 'RESTRICT', 'RESTRICT'],
            $foreignKeys['course_id'] ?? null,
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function columnsFor(string $table): array
    {
        $rows = Db::query(
            'SELECT COLUMN_NAME, IS_NULLABLE, COLUMN_TYPE
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

    /** @return array<string, array{columns: string, unique: bool}> */
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

    /** @return array<string, array{string, string, string, string}> */
    private function foreignKeysFor(string $table): array
    {
        $rows = Db::query(
            "SELECT kcu.CONSTRAINT_NAME,
                    GROUP_CONCAT(kcu.COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',') AS COLUMN_NAMES,
                    kcu.REFERENCED_TABLE_NAME,
                    GROUP_CONCAT(kcu.REFERENCED_COLUMN_NAME ORDER BY kcu.ORDINAL_POSITION SEPARATOR ',')
                        AS REFERENCED_COLUMN_NAMES,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE kcu
             JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = kcu.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = kcu.CONSTRAINT_NAME
              AND rc.TABLE_NAME = kcu.TABLE_NAME
             WHERE kcu.TABLE_SCHEMA = DATABASE()
               AND kcu.TABLE_NAME = ?
               AND kcu.REFERENCED_TABLE_NAME IS NOT NULL
             GROUP BY kcu.CONSTRAINT_NAME, kcu.REFERENCED_TABLE_NAME, rc.DELETE_RULE, rc.UPDATE_RULE",
            [$table],
        );

        $foreignKeys = [];
        foreach ($rows as $row) {
            $foreignKeys[(string) $row['COLUMN_NAMES']] = [
                (string) $row['REFERENCED_TABLE_NAME'],
                (string) $row['REFERENCED_COLUMN_NAMES'],
                (string) $row['DELETE_RULE'],
                (string) $row['UPDATE_RULE'],
            ];
        }
        return $foreignKeys;
    }
}
