<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Complete the Phase 12 review schema without rewriting its applied migration.
 */
final class HardenReviewSchema extends AbstractMigration
{
    public function up(): void
    {
        $replies = $this->table('review_replies')
            ->addColumn('updated_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'created_at',
            ])
            ->addColumn('hidden_reason', 'string', [
                'limit' => 255,
                'null' => true,
                'default' => null,
                'after' => 'visibility',
            ])
            ->addColumn('hidden_by_staff_id', 'biginteger', [
                'signed' => false,
                'null' => true,
                'default' => null,
                'after' => 'hidden_reason',
            ])
            ->addColumn('hidden_at', 'datetime', [
                'null' => true,
                'default' => null,
                'after' => 'hidden_by_staff_id',
            ])
            ->addIndex(
                ['review_id', 'visibility', 'parent_id', 'id'],
                ['name' => 'idx_review_replies_thread'],
            )
            ->addForeignKey('hidden_by_staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_review_replies_hidden_by_staff',
            ]);
        $replies->update();

        $this->execute('UPDATE review_replies SET updated_at = created_at WHERE updated_at IS NULL');
        $this->table('review_replies')
            ->changeColumn('updated_at', 'datetime', [
                'null' => false,
                'default' => 'CURRENT_TIMESTAMP',
            ])
            ->update();

        $this->execute(
            'ALTER TABLE reviews '
            . 'ADD CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)',
        );
    }

    public function down(): void
    {
        $this->execute('ALTER TABLE reviews DROP CHECK chk_reviews_rating');

        $replies = $this->table('review_replies');
        if ($replies->hasForeignKey('hidden_by_staff_id')) {
            $replies->dropForeignKey('hidden_by_staff_id')->update();
        }

        $replies = $this->table('review_replies');
        if ($replies->hasIndexByName('idx_review_replies_thread')) {
            $replies->removeIndexByName('idx_review_replies_thread');
        }
        $replies
            ->removeColumn('updated_at')
            ->removeColumn('hidden_reason')
            ->removeColumn('hidden_by_staff_id')
            ->removeColumn('hidden_at')
            ->update();
    }
}
