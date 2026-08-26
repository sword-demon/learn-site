<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class ReviewSchemaIntegrationTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    public function testRatingIsConstrainedToFiveStars(): void
    {
        $checks = $this->checksFor('reviews');

        $this->assertArrayHasKey('chk_reviews_rating', $checks);
        $this->assertStringContainsString('between 1 and 5', strtolower($checks['chk_reviews_rating']));
    }

    public function testReplyColumnsSupportEditingAndModeration(): void
    {
        $columns = $this->columnsFor('review_replies');

        $this->assertSame('NO', $columns['updated_at']['IS_NULLABLE'] ?? null);
        foreach (['hidden_reason', 'hidden_by_staff_id', 'hidden_at'] as $column) {
            $this->assertSame('YES', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
    }

    public function testModerationLogCapturesReviewAndReplyActions(): void
    {
        $columns = $this->columnsFor('moderation_logs');

        foreach (['id', 'object_type', 'object_id', 'action', 'reason', 'staff_id', 'created_at'] as $column) {
            $this->assertSame('NO', $columns[$column]['IS_NULLABLE'] ?? null, $column);
        }
        $this->assertSame("enum('review','reply')", $columns['object_type']['COLUMN_TYPE'] ?? null);
        $this->assertSame("enum('hide','restore')", $columns['action']['COLUMN_TYPE'] ?? null);
    }

    public function testModerationForeignKeysPreserveStaffAttribution(): void
    {
        $replyForeignKeys = $this->foreignKeysFor('review_replies');
        $logForeignKeys = $this->foreignKeysFor('moderation_logs');

        $this->assertSame(
            ['staff_users', 'account_id', 'RESTRICT', 'RESTRICT'],
            $replyForeignKeys['hidden_by_staff_id'] ?? null,
        );
        $this->assertSame(
            ['staff_users', 'account_id', 'RESTRICT', 'RESTRICT'],
            $logForeignKeys['staff_id'] ?? null,
        );
    }

    public function testIndexesMatchThreadAndAuditQueries(): void
    {
        $replyIndexes = array_values($this->indexesFor('review_replies'));
        $logIndexes = array_values($this->indexesFor('moderation_logs'));

        $this->assertContains('review_id,visibility,parent_id,id', $replyIndexes);
        $this->assertContains('object_type,object_id,id', $logIndexes);
        $this->assertContains('staff_id,created_at', $logIndexes);
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

    /** @return array<string, string> */
    private function checksFor(string $table): array
    {
        $rows = Db::query(
            'SELECT tc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
             FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
             JOIN INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
               ON cc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND cc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
             WHERE tc.TABLE_SCHEMA = DATABASE()
               AND tc.TABLE_NAME = ?
               AND tc.CONSTRAINT_TYPE = ?',
            [$table, 'CHECK'],
        );

        $checks = [];
        foreach ($rows as $row) {
            $checks[(string) $row['CONSTRAINT_NAME']] = (string) $row['CHECK_CLAUSE'];
        }
        return $checks;
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
