<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Learning maps + stages + stage-courses + enrollments (Phase 13 / US6).
 *
 * Rules from data-model §学习地图:
 *   - 地图有 department_id 与 status。
 *   - 阶段有序（map_stages.sort_order）。
 *   - 地图内课程不重复（map_stage_courses 唯一索引）。
 *   - 发布禁止空地图 / 空阶段 / 未发布课程。
 *   - 进度 = 已完成课程数 / 当前有效课程数；收费课不因加入地图授权。
 */
final class CreateMaps extends AbstractMigration
{
    public function change(): void
    {
        $maps = $this->table('learning_maps', ['id' => false, 'primary_key' => ['id']]);
        $maps
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('department_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('summary', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('status', 'enum', [
                'values' => ['draft', 'published'],
                'default' => 'draft',
                'null' => false,
            ])
            ->addColumn('created_by_staff_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['department_id', 'status'], ['name' => 'idx_maps_dept_status'])
            ->addForeignKey('department_id', 'departments', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('created_by_staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->create();

        $stages = $this->table('map_stages', ['id' => false, 'primary_key' => ['id']]);
        $stages
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('map_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 128, 'null' => false])
            ->addColumn('summary', 'string', ['limit' => 255, 'null' => true, 'default' => null])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['map_id', 'sort_order'], ['name' => 'idx_stages_map_order'])
            ->addForeignKey('map_id', 'learning_maps', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->create();

        $stageCourses = $this->table('map_stage_courses', ['id' => false, 'primary_key' => ['id']]);
        $stageCourses
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('stage_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('sort_order', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['stage_id', 'course_id'], ['unique' => true, 'name' => 'uq_stage_course'])
            ->addForeignKey('stage_id', 'map_stages', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('course_id', 'courses', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->create();

        $enrollments = $this->table('map_enrollments', ['id' => false, 'primary_key' => ['id']]);
        $enrollments
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('map_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('enrolled_at', 'datetime', ['null' => false])
            ->addColumn('completed_courses', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('total_courses', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('progress_percent', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('completed_at', 'datetime', ['null' => true, 'default' => null])
            ->addIndex(['learner_id'], ['name' => 'idx_enrollments_learner'])
            ->addIndex(['map_id', 'learner_id'], ['unique' => true, 'name' => 'uq_map_learner'])
            ->addForeignKey('map_id', 'learning_maps', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('learner_id', 'accounts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->create();
    }
}
