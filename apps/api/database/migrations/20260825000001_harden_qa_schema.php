<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Tighten the Phase 11 Q&A schema without rewriting its applied migration.
 */
final class HardenQaSchema extends AbstractMigration
{
    public function up(): void
    {
        $questions = $this->table('questions');
        if ($questions->hasForeignKey('chapter_id')) {
            $questions->dropForeignKey('chapter_id')->update();
        }

        $questions = $this->table('questions')
            ->changeColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('chapter_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('lesson_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('learner_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('title', 'string', ['limit' => 128, 'null' => false])
            ->changeColumn('body', 'text', ['null' => false])
            ->changeColumn('status', 'enum', [
                'values' => ['pending', 'answered', 'closed'],
                'default' => 'pending',
                'null' => false,
            ])
            ->changeColumn('created_at', 'datetime', ['null' => false])
            ->changeColumn('updated_at', 'datetime', ['null' => false])
            ->addForeignKey('chapter_id', 'chapters', 'id', [
                'delete' => 'CASCADE',
                'update' => 'CASCADE',
                'constraint' => 'fk_questions_chapter',
            ]);
        if (!$questions->hasForeignKey('answered_by_staff_id')) {
            $questions->addForeignKey('answered_by_staff_id', 'staff_users', 'account_id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE',
                'constraint' => 'fk_questions_answered_by_staff',
            ]);
        }
        if (!$questions->hasIndex(['status', 'id'])) {
            $questions->addIndex(['status', 'id'], ['name' => 'idx_questions_status_id']);
        }
        if (!$questions->hasIndex(['lesson_id', 'id'])) {
            $questions->addIndex(['lesson_id', 'id'], ['name' => 'idx_questions_lesson_id']);
        }
        $questions->update();

        $messages = $this->table('question_messages')
            ->changeColumn('question_id', 'biginteger', ['signed' => false, 'null' => false])
            ->changeColumn('kind', 'enum', [
                'values' => ['questioner', 'admin', 'system'],
                'null' => false,
            ])
            ->changeColumn('body', 'text', ['null' => false])
            ->changeColumn('created_at', 'datetime', ['null' => false]);
        if (!$messages->hasIndex(['question_id', 'id'])) {
            $messages->addIndex(['question_id', 'id'], ['name' => 'idx_question_messages_question_id']);
        }
        $messages->update();
    }

    public function down(): void
    {
        $messages = $this->table('question_messages');
        if ($messages->hasIndexByName('idx_question_messages_question_id')) {
            $messages->removeIndexByName('idx_question_messages_question_id');
        }
        $messages
            ->changeColumn('question_id', 'biginteger', ['signed' => false, 'null' => true])
            ->changeColumn('kind', 'enum', [
                'values' => ['questioner', 'admin', 'system'],
                'null' => true,
            ])
            ->changeColumn('body', 'text', ['null' => true])
            ->changeColumn('created_at', 'datetime', ['null' => true])
            ->update();

        $questions = $this->table('questions');
        if ($questions->hasForeignKey('answered_by_staff_id')) {
            $questions->dropForeignKey('answered_by_staff_id');
        }
        if ($questions->hasForeignKey('chapter_id')) {
            $questions->dropForeignKey('chapter_id');
        }
        if ($questions->hasIndexByName('idx_questions_status_id')) {
            $questions->removeIndexByName('idx_questions_status_id');
        }
        if ($questions->hasIndexByName('idx_questions_lesson_id')) {
            $questions->removeIndexByName('idx_questions_lesson_id');
        }
        $questions->update();

        $this->table('questions')
            ->changeColumn('course_id', 'biginteger', ['signed' => false, 'null' => true])
            ->changeColumn('chapter_id', 'biginteger', ['signed' => false, 'null' => true])
            ->changeColumn('lesson_id', 'biginteger', ['signed' => false, 'null' => true])
            ->changeColumn('learner_id', 'biginteger', ['signed' => false, 'null' => true])
            ->changeColumn('title', 'string', ['limit' => 128, 'null' => true])
            ->changeColumn('body', 'text', ['null' => true])
            ->changeColumn('status', 'enum', [
                'values' => ['pending', 'answered', 'closed'],
                'default' => 'pending',
                'null' => true,
            ])
            ->changeColumn('created_at', 'datetime', ['null' => true])
            ->changeColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('chapter_id', 'chapters', 'id', [
                'delete' => 'SET NULL',
                'update' => 'CASCADE',
            ])
            ->update();
    }
}
