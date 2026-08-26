<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Complete the Phase 13 learning-map schema without rewriting its applied migration.
 */
final class HardenMapSchema extends AbstractMigration
{
    public function up(): void
    {
        $duplicates = $this->getAdapter()->getConnection()->query(
            'SELECT s.map_id, msc.course_id, COUNT(*) AS duplicate_count
             FROM map_stage_courses msc
             JOIN map_stages s ON s.id = msc.stage_id
             GROUP BY s.map_id, msc.course_id
             HAVING COUNT(*) > 1
             LIMIT 5',
        )->fetchAll(PDO::FETCH_ASSOC);
        if ($duplicates !== []) {
            throw new RuntimeException(
                'Cannot enforce map-level course uniqueness while duplicate map courses exist: '
                . json_encode($duplicates, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            );
        }

        $this->table('learning_maps')
            ->addColumn('cover_url', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'summary',
            ])
            ->addColumn('objective', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'cover_url',
            ])
            ->addColumn('audience', 'text', [
                'null' => true,
                'default' => null,
                'after' => 'objective',
            ])
            ->update();

        $this->table('map_stages')
            ->addIndex(
                ['id', 'map_id'],
                ['unique' => true, 'name' => 'uq_map_stage_identity'],
            )
            ->update();

        $this->table('map_stage_courses')
            ->addColumn('map_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'stage_id',
            ])
            ->update();

        $this->execute(
            'UPDATE map_stage_courses msc
             JOIN map_stages s ON s.id = msc.stage_id
             SET msc.map_id = s.map_id',
        );

        $this->table('map_stage_courses')
            ->changeColumn('map_id', 'biginteger', [
                'signed' => false,
                'null' => false,
            ])
            ->update();

        $stageCourses = $this->table('map_stage_courses');
        if ($stageCourses->hasForeignKey('stage_id')) {
            $stageCourses->dropForeignKey('stage_id');
        }
        if ($stageCourses->hasForeignKey('course_id')) {
            $stageCourses->dropForeignKey('course_id');
        }
        $stageCourses->update();

        $this->table('map_stage_courses')
            ->addIndex(
                ['map_id', 'course_id'],
                ['unique' => true, 'name' => 'uq_map_course'],
            )
            ->addIndex(
                ['stage_id', 'map_id'],
                ['name' => 'idx_map_stage_courses_stage_map'],
            )
            ->addForeignKey(['stage_id', 'map_id'], 'map_stages', ['id', 'map_id'], [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_map_stage_courses_stage_map',
            ])
            ->addForeignKey('course_id', 'courses', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_map_stage_courses_course',
            ])
            ->update();
    }

    public function down(): void
    {
        $stageCourses = $this->table('map_stage_courses');
        if ($stageCourses->hasForeignKey(['stage_id', 'map_id'])) {
            $stageCourses->dropForeignKey(['stage_id', 'map_id']);
        }
        if ($stageCourses->hasForeignKey('course_id')) {
            $stageCourses->dropForeignKey('course_id');
        }
        if ($stageCourses->hasIndexByName('uq_map_course')) {
            $stageCourses->removeIndexByName('uq_map_course');
        }
        if ($stageCourses->hasIndexByName('idx_map_stage_courses_stage_map')) {
            $stageCourses->removeIndexByName('idx_map_stage_courses_stage_map');
        }
        $stageCourses->update();

        $this->table('map_stage_courses')
            ->removeColumn('map_id')
            ->addForeignKey('stage_id', 'map_stages', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('course_id', 'courses', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->update();

        $stages = $this->table('map_stages');
        if ($stages->hasIndexByName('uq_map_stage_identity')) {
            $stages->removeIndexByName('uq_map_stage_identity')->update();
        }

        $this->table('learning_maps')
            ->removeColumn('cover_url')
            ->removeColumn('objective')
            ->removeColumn('audience')
            ->update();
    }
}
