<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Reviews + review replies (Phase 12 / US5).
 *
 * Per FR-046 + data-model §互动:
 *   - One active review per (learner, course). Hidden rows do not count.
 *   - 1-5 stars + body. Replies are a tree (parent_id nullable).
 *   - Visibility = public | hidden. Hidden reviews are excluded from
 *     aggregate score.
 *
 * Indexes:
 *   - uq_active_review covers the "one active per (learner,course)"
 *     rule at the database level. Hidden rows are exempt by storing
 *     status='hidden' and checking the partial uniqueness with a
 *     generated column trick (see below).
 */
final class CreateReviews extends AbstractMigration
{
    public function change(): void
    {
        $reviews = $this->table('reviews', ['id' => false, 'primary_key' => ['id']]);
        $reviews
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('rating', 'integer', [
                'limit' => 1,
                'null' => false,
            ])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('visibility', 'enum', [
                'values' => ['public', 'hidden'],
                'default' => 'public',
                'null' => false,
            ])
            // Synthetic column used to enforce "one active review per
            // learner per course". NULL when hidden → MySQL treats NULLs
            // as distinct in unique indexes, so hidden rows don't block a
            // fresh public review.
            ->addColumn('active_key', 'string', [
                'limit' => 48,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('hidden_reason', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('hidden_by_staff_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('hidden_at', 'datetime', [
                'null' => true,
                'default' => null,
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['course_id', 'visibility'], ['name' => 'idx_reviews_course_visibility'])
            ->addIndex(['learner_id'], ['name' => 'idx_reviews_learner'])
            ->addIndex(['active_key'], ['unique' => true, 'name' => 'uq_active_review'])
            ->addForeignKey('course_id', 'courses', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('learner_id', 'accounts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('hidden_by_staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->create();

        $replies = $this->table('review_replies', ['id' => false, 'primary_key' => ['id']]);
        $replies
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false, 'null' => false])
            ->addColumn('review_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('parent_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            // 'learner' (author of the review or another learner) | 'admin' | 'system'.
            // Replies are flat-stamped by kind; the parent_id tree carries
            // the actual nesting (max 3 levels — enforced in service).
            ->addColumn('kind', 'enum', [
                'values' => ['learner', 'admin', 'system'],
                'default' => 'learner',
                'null' => false,
            ])
            ->addColumn('author_learner_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('author_staff_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
            ])
            ->addColumn('body', 'text', ['null' => false])
            ->addColumn('visibility', 'enum', [
                'values' => ['public', 'hidden'],
                'default' => 'public',
                'null' => false,
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(['review_id'], ['name' => 'idx_review_replies_review'])
            ->addIndex(['parent_id'], ['name' => 'idx_review_replies_parent'])
            ->addForeignKey('review_id', 'reviews', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('parent_id', 'review_replies', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('author_learner_id', 'accounts', 'id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->addForeignKey('author_staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
            ])
            ->create();
    }
}
