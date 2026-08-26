<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Q&A schema (Phase 11 — User Story 4).
 *
 *  - questions: each row binds a learner's question to one course, chapter,
 *    and lesson. status='pending' → 'answered' → 'closed'. There is no
 *    private-vs-public split; all questions are visible to any authorised
 *    learner of the course (FR-046 + data-model §互动).
 *  - question_messages: append-only thread. kind ∈ 'questioner' / 'admin' /
 *    'system'. Stamping answered_at on the parent question is done by the
 *    service when the first admin message lands; further messages don't
 *    reopen the answered status unless explicitly re-opened.
 *
 * FK on delete policy:
 *   lessons        ← questions     CASCADE  (lesson archive deletes the thread)
 *   learners acct    ← questions     RESTRICT (no learner wipe)
 *   questions      ← question_messages CASCADE
 *   learners acct   ← question_messages RESTRICT
 *   staff_users    ← question_messages SET NULL (keep thread on staff delete)
 */
final class CreateQa extends AbstractMigration
{
    public function change(): void
    {
        $this->table('questions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('chapter_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('lesson_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 128])
            ->addColumn('body', 'text')
            ->addColumn('status', 'enum', ['values' => ['pending', 'answered', 'closed'], 'default' => 'pending'])
            ->addColumn('answered_at', 'datetime', ['null' => true])
            ->addColumn('answered_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('chapter_id', 'chapters', 'id', ['delete' => 'SET NULL', 'update' => 'CASCADE'])
            ->addForeignKey('lesson_id', 'lessons', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['lesson_id', 'created_at'])
            ->addIndex(['course_id', 'status'])
            ->addIndex(['learner_id', 'created_at'])
            ->create();

        $this->table('question_messages', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('question_id', 'biginteger', ['signed' => false])
            ->addColumn('kind', 'enum', ['values' => ['questioner', 'admin', 'system']])
            ->addColumn('author_learner_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('author_staff_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('body', 'text')
            ->addColumn('created_at', 'datetime')
            ->addForeignKey('question_id', 'questions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('author_learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('author_staff_id', 'staff_users', 'account_id', ['delete' => 'SET NULL', 'update' => 'CASCADE'])
            ->addIndex(['question_id', 'created_at'])
            ->create();
    }
}
