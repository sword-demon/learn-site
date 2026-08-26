<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Learning / entitlement / order schema (Phase 6 — User Story 3).
 *
 *  - orders            : purchase snapshots, immutable, status machine
 *  - course_entitlements : effective grant to a learner; unique while active
 *  - course_enrollments  : progress aggregate per (learner, course)
 *  - lesson_progresses   : per-lesson progress and completion flag
 *
 * Rules baked into the schema (data-model.md §交易与访问 + §学习):
 *   - orders.{(list_price, sale_price, paid_amount)} are immutable snapshots;
 *     course price changes never alter historical rows.
 *   - course_entitlements is unique by (learner_id, course_id) while status
 *     is 'active'; reactivation inserts a new row, not a patch.
 *   - lesson_progresses.completed is monotonic — flipped 0 → 1 only.
 *
 * FK on delete policy:
 *   learners → orders               RESTRICT  (cannot wipe learner with history)
 *   courses  → orders               RESTRICT  (course delete guard enforces this)
 *   orders   → course_entitlements  RESTRICT  (purchases are auditable history)
 *   learners → course_entitlements  RESTRICT
 *   courses  → course_entitlements  RESTRICT
 *   learners → course_enrollments   RESTRICT
 *   courses  → course_enrollments   RESTRICT
 *   learners → lesson_progresses    RESTRICT
 *   lessons  → lesson_progresses    RESTRICT
 */
final class CreateLearning extends AbstractMigration
{
    public function change(): void
    {
        // orders — payment snapshots. provider='fake' for now; 'wechat_native'
        // is reserved for a future real adapter (Phase beyond MVP). The status
        // machine is the FR contract: pending → succeeded|failed|cancelled|unknown.
        // succeeded_at is set only on succeeded (we never stamp non-success).
        $this->table('orders', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('list_price_snapshot', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('sale_price_snapshot', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('paid_amount', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('currency', 'string', ['limit' => 8, 'default' => 'CNY'])
            ->addColumn('status', 'enum', [
                'values' => ['pending', 'succeeded', 'failed', 'cancelled', 'unknown'],
                'default' => 'pending',
            ])
            ->addColumn('provider', 'string', ['limit' => 32])
            ->addColumn('provider_ref', 'string', ['limit' => 128, 'null' => true])
            ->addColumn('succeeded_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['learner_id', 'created_at'])
            ->addIndex(['course_id'])
            ->addIndex(['status'])
            ->create();

        // course_entitlements — active grant to a learner. A learner cannot
        // hold two active entitlements for the same course; the unique index
        // covers status='active' via the composite (learner_id, course_id,
        // status) plus a uniqueness check in EntitlementService. Revoked rows
        // remain for audit; re-join creates a new row.
        $this->table('course_entitlements', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('source', 'enum', ['values' => ['free', 'purchase']])
            ->addColumn('order_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('status', 'enum', ['values' => ['active', 'revoked'], 'default' => 'active'])
            ->addColumn('revoked_at', 'datetime', ['null' => true])
            ->addColumn('revoked_reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('revoked_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('order_id', 'orders', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['learner_id', 'course_id', 'status'], ['unique' => true])
            ->addIndex(['status'])
            ->create();

        // course_enrollments — aggregate progress per (learner, course).
        // progress_percent ∈ [0,100], last_lesson_id nullable, completed_at
        // is set once when all enabled lessons reach completed=1. Row is
        // never deleted (revoke-free retains it).
        $this->table('course_enrollments', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('progress_percent', 'integer', ['default' => 0])
            ->addColumn('last_lesson_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('last_position', 'integer', ['default' => 0])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('last_lesson_id', 'lessons', 'id', ['delete' => 'SET NULL', 'update' => 'CASCADE'])
            ->addIndex(['learner_id', 'course_id'], ['unique' => true])
            ->addIndex(['learner_id', 'updated_at'])
            ->create();

        // lesson_progresses — one row per (learner, lesson). completed flips
        // 0 → 1 monotonically. position_seconds tracks last playback head for
        // video lessons; markdown/pdf only set position_seconds=1 when opened.
        $this->table('lesson_progresses', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('lesson_id', 'biginteger', ['signed' => false])
            ->addColumn('position_seconds', 'integer', ['default' => 0])
            ->addColumn('completed', 'boolean', ['default' => false])
            ->addColumn('completed_at', 'datetime', ['null' => true])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('learner_id', 'accounts', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('lesson_id', 'lessons', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['learner_id', 'lesson_id'], ['unique' => true])
            ->addIndex(['learner_id', 'completed'])
            ->create();
    }
}
