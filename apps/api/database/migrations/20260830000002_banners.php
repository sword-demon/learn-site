<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * 006-admin-banner-carousel - site-wide banner records.
 *
 * Image files live below the separate banners/ storage prefix. Rows are kept
 * for audit and operational recovery after a soft delete.
 */
final class Banners extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('banners')) {
            return;
        }

        $this->table('banners', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('image_url', 'string', ['limit' => 512])
            ->addColumn('image_key', 'string', ['limit' => 255])
            ->addColumn('link_url', 'string', ['limit' => 2048, 'null' => true])
            ->addColumn('sort_order', 'integer', ['signed' => false, 'default' => 0])
            ->addColumn('is_enabled', 'boolean', ['default' => true])
            ->addColumn('deleted_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'timestamp', [
                'default' => 'CURRENT_TIMESTAMP',
                'update' => 'CURRENT_TIMESTAMP',
            ])
            ->addIndex(['deleted_at', 'is_enabled', 'sort_order'], ['name' => 'idx_banners_public'])
            ->addIndex(['deleted_at', 'sort_order'], ['name' => 'idx_banners_admin'])
            ->addIndex(['created_at'], ['name' => 'idx_banners_created_at'])
            ->create();
    }

    public function down(): void
    {
        if ($this->hasTable('banners')) {
            $this->table('banners')->drop()->save();
        }
    }
}
