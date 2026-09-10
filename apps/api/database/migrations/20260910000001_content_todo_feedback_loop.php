<?php

declare(strict_types=1);

use Phinx\Db\Adapter\MysqlAdapter;
use Phinx\Migration\AbstractMigration;

/**
 * Content todo workflow for learner questions and private course feedback.
 *
 * The inbox remains an operational projection; these tables own the durable
 * content workflow, candidate approval history, and final result state.
 */
final class ContentTodoFeedbackLoop extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('content_todos')) {
            $this->table('content_todos', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('source_type', 'enum', [
                    'values' => ['question_pending', 'feedback_pending'],
                    'null' => false,
                ])
                ->addColumn('source_key', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('source_course_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_course_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_chapter_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_lesson_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('label', 'enum', [
                    'values' => ['error', 'missing_example', 'resource_problem', 'other'],
                    'null' => true,
                ])
                ->addColumn('workflow_status', 'enum', [
                    'values' => ['untriaged', 'triaged', 'awaiting_approval', 'resolved', 'closed'],
                    'default' => 'untriaged',
                    'null' => false,
                ])
                ->addColumn('first_response_at', 'datetime', ['null' => true])
                ->addColumn('first_response_kind', 'string', ['limit' => 32, 'null' => true])
                ->addColumn('first_response_notification_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('result_type', 'enum', [
                    'values' => ['content_updated', 'help_center_candidate', 'responded_only', 'closed_no_change'],
                    'null' => true,
                ])
                ->addColumn('close_reason_code', 'enum', [
                    'values' => ['duplicate', 'not_actionable', 'already_covered', 'not_planned'],
                    'null' => true,
                ])
                ->addColumn('close_reason_note', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('resolved_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('resolved_at', 'datetime', ['null' => true])
                ->addColumn('version', 'integer', ['signed' => false, 'default' => 1, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addIndex(['source_type', 'source_key'], [
                    'unique' => true,
                    'name' => 'uk_content_todos_source',
                ])
                ->addIndex(['source_course_id', 'workflow_status', 'updated_at'], [
                    'name' => 'idx_content_todos_scope_status',
                ])
                ->addIndex(['workflow_status', 'label', 'updated_at'], [
                    'name' => 'idx_content_todos_workflow',
                ])
                ->addIndex(['target_course_id', 'target_chapter_id', 'target_lesson_id'], [
                    'name' => 'idx_content_todos_target',
                ])
                ->addForeignKey('source_course_id', 'courses', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_source_course',
                ])
                ->addForeignKey('target_course_id', 'courses', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_target_course',
                ])
                ->addForeignKey('target_chapter_id', 'chapters', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_target_chapter',
                ])
                ->addForeignKey('target_lesson_id', 'lessons', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_target_lesson',
                ])
                ->addForeignKey('first_response_notification_id', 'learner_notifications', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_first_response_notification',
                ])
                ->addForeignKey('resolved_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todos_resolved_by',
                ])
                ->create();
        }

        if (!$this->hasTable('content_todo_candidates')) {
            $this->table('content_todo_candidates', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('content_todo_id', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('version', 'integer', ['signed' => false, 'null' => false])
                ->addColumn('target_course_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_chapter_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_lesson_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('target_kind', 'enum', [
                    'values' => ['course_intro', 'lesson_markdown', 'help_center_candidate'],
                    'null' => false,
                ])
                ->addColumn('body', 'text', ['limit' => MysqlAdapter::TEXT_REGULAR, 'null' => false])
                ->addColumn('body_format', 'enum', [
                    'values' => ['html', 'markdown', 'plain'],
                    'null' => false,
                ])
                ->addColumn('base_content_fingerprint', 'char', ['limit' => 64, 'null' => false])
                ->addColumn('generator', 'string', ['limit' => 32, 'default' => 'server_template', 'null' => false])
                ->addColumn('status', 'enum', [
                    'values' => ['draft', 'superseded', 'approved', 'rejected'],
                    'default' => 'draft',
                    'null' => false,
                ])
                ->addColumn('generated_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('generated_at', 'datetime', ['null' => false])
                ->addColumn('approved_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('approved_at', 'datetime', ['null' => true])
                ->addColumn('rejected_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
                ->addColumn('rejected_at', 'datetime', ['null' => true])
                ->addColumn('rejection_reason', 'string', ['limit' => 500, 'null' => true])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addIndex(['content_todo_id', 'version'], [
                    'unique' => true,
                    'name' => 'uk_content_todo_candidate_version',
                ])
                ->addIndex(['content_todo_id', 'status', 'version'], [
                    'name' => 'idx_content_todo_candidate_status',
                ])
                ->addIndex(['target_course_id', 'target_chapter_id', 'target_lesson_id'], [
                    'name' => 'idx_content_todo_candidate_target',
                ])
                ->addForeignKey('content_todo_id', 'content_todos', 'id', [
                    'delete' => 'CASCADE',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_todo',
                ])
                ->addForeignKey('target_course_id', 'courses', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_course',
                ])
                ->addForeignKey('target_chapter_id', 'chapters', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_chapter',
                ])
                ->addForeignKey('target_lesson_id', 'lessons', 'id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_lesson',
                ])
                ->addForeignKey('generated_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_generated_by',
                ])
                ->addForeignKey('approved_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_approved_by',
                ])
                ->addForeignKey('rejected_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'SET_NULL',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_content_todo_candidates_rejected_by',
                ])
                ->create();
        }
    }

    public function down(): void
    {
        foreach (['content_todo_candidates', 'content_todos'] as $table) {
            if (!$this->hasTable($table)) {
                continue;
            }
            $row = $this->fetchRow("SELECT COUNT(*) AS aggregate FROM {$table}");
            if ((int) ($row['aggregate'] ?? 0) > 0) {
                throw new RuntimeException(
                    'content todo rollback refused: business workflow data exists; '
                    . 'export or explicitly compensate it before removing this schema.',
                );
            }
        }

        if ($this->hasTable('content_todo_candidates')) {
            $this->table('content_todo_candidates')->drop()->save();
        }
        if ($this->hasTable('content_todos')) {
            $this->table('content_todos')->drop()->save();
        }
    }
}
