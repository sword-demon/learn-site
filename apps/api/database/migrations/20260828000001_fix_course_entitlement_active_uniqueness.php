<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Keep one active entitlement while allowing unlimited revoked history.
 *
 * The original learning migration used (learner_id, course_id, status) as a
 * unique key. That also made a second revoked row impossible. MySQL has no
 * partial indexes, so the active-only key is represented by a generated
 * nullable marker: revoked rows generate NULL and MySQL permits multiple NULLs
 * in a unique index. The marker only references status; the foreign-key
 * columns remain ordinary indexed columns.
 */
final class FixCourseEntitlementActiveUniqueness extends AbstractMigration
{
    public function up(): void
    {
        $invalidRows = $this->getAdapter()->getConnection()->query(
            "SELECT id
             FROM course_entitlements
             WHERE learner_id IS NULL OR course_id IS NULL OR source IS NULL OR status IS NULL
             LIMIT 1",
        )->fetch(PDO::FETCH_ASSOC);
        if ($invalidRows !== false) {
            throw new RuntimeException(
                'Cannot enforce course entitlement constraints while NULL learner/course/source/status rows exist; '
                . 'repair them with a reviewed forward migration first.',
            );
        }

        $this->table('course_entitlements')
            ->changeColumn('learner_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('source', 'enum', [
                'values' => ['free', 'purchase'],
                'null' => false,
            ])
            ->changeColumn('status', 'enum', [
                'values' => ['active', 'revoked'],
                'default' => 'active',
                'null' => false,
            ])
            ->update();

        $legacyIndexes = $this->getAdapter()->getConnection()->query(
            "SELECT INDEX_NAME, NON_UNIQUE,
                    GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX SEPARATOR ',') AS INDEX_COLUMNS
             FROM INFORMATION_SCHEMA.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'course_entitlements'
             GROUP BY INDEX_NAME, NON_UNIQUE",
        )->fetchAll(PDO::FETCH_ASSOC);

        $indexColumns = array_map(
            static fn (array $index): string => (string) ($index['INDEX_COLUMNS'] ?? ''),
            $legacyIndexes,
        );
        $table = $this->table('course_entitlements');
        $supportingIndexesChanged = false;
        if (!in_array('learner_id', $indexColumns, true)) {
            $table->addIndex(['learner_id'], ['name' => 'idx_course_entitlements_learner']);
            $supportingIndexesChanged = true;
        }
        if (!in_array('course_id', $indexColumns, true)) {
            $table->addIndex(['course_id'], ['name' => 'idx_course_entitlements_course']);
            $supportingIndexesChanged = true;
        }
        if ($supportingIndexesChanged) {
            $table->update();
        }

        foreach ($legacyIndexes as $index) {
            if ((int) ($index['NON_UNIQUE'] ?? 1) !== 0
                || (string) ($index['INDEX_COLUMNS'] ?? '') !== 'learner_id,course_id,status'
            ) {
                continue;
            }

            $name = (string) $index['INDEX_NAME'];
            $quotedName = str_replace(chr(96), chr(96) . chr(96), $name);
            $this->execute(sprintf(
                'ALTER TABLE course_entitlements DROP INDEX %c%s%c',
                96,
                $quotedName,
                96,
            ));
        }

        $table = $this->table('course_entitlements');
        if (!$table->hasColumn('active_marker')) {
            $this->execute(
                "ALTER TABLE course_entitlements
                 ADD COLUMN active_marker TINYINT
                 GENERATED ALWAYS AS (
                     IF(status = 'active', 1, NULL)
                 ) STORED AFTER status",
            );
        }

        $table = $this->table('course_entitlements');
        if (!$table->hasIndexByName('uq_course_entitlements_active')) {
            $table
                ->addIndex(['learner_id', 'course_id', 'active_marker'], [
                    'unique' => true,
                    'name' => 'uq_course_entitlements_active',
                ])
                ->update();
        }
    }

    public function down(): void
    {
        $duplicates = $this->getAdapter()->getConnection()->query(
            "SELECT learner_id, course_id, COUNT(*) AS revoked_count
             FROM course_entitlements
             WHERE status = 'revoked'
             GROUP BY learner_id, course_id
             HAVING COUNT(*) > 1
             LIMIT 1",
        )->fetch(PDO::FETCH_ASSOC);
        if ($duplicates !== false) {
            throw new RuntimeException(
                'Cannot roll back active entitlement uniqueness with multiple revoked rows for one learner/course; '
                . 'use a forward migration or restore a verified backup instead.',
            );
        }

        $table = $this->table('course_entitlements');
        if ($table->hasIndexByName('uq_course_entitlements_active')) {
            $table->removeIndexByName('uq_course_entitlements_active')->update();
        }

        $table = $this->table('course_entitlements');
        if ($table->hasColumn('active_marker')) {
            $table->removeColumn('active_marker')->update();
        }

        $this->execute(
            'ALTER TABLE course_entitlements
             ADD UNIQUE KEY uq_course_entitlements_learner_course_status
             (learner_id, course_id, status)',
        );
    }
}
