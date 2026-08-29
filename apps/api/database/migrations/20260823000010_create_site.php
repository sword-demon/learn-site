<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateSite extends AbstractMigration
{
    public function up(): void
    {
        $this->table('site_profile', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                'default' => 1,
                'null' => false,
            ])
            ->addColumn('title', 'string', ['limit' => 80, 'default' => '学习平台'])
            ->addColumn('subtitle', 'string', ['limit' => 160, 'default' => '选课、学习、交流'])
            ->addColumn('body_html', 'text', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM,
                'null' => true,
            ])
            ->addColumn('contact_email', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('updated_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->addForeignKey('updated_by_staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_site_profile_updated_by_staff',
            ])
            ->create();
        $this->execute(
            'ALTER TABLE site_profile '
            . 'ADD CONSTRAINT chk_site_profile_single_row CHECK (id = 1)',
        );

        $this->table('moderation_logs', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', [
                'identity' => true,
                'signed' => false,
                'null' => false,
            ])
            ->addColumn('object_type', 'enum', [
                'values' => ['review', 'reply'],
                'null' => false,
            ])
            ->addColumn('object_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('action', 'enum', [
                'values' => ['hide', 'restore'],
                'null' => false,
            ])
            ->addColumn('reason', 'string', [
                'limit' => 255,
                'null' => false,
                'default' => '',
            ])
            ->addColumn('staff_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addIndex(
                ['object_type', 'object_id', 'id'],
                ['name' => 'idx_moderation_logs_object'],
            )
            ->addIndex(
                ['action', 'created_at'],
                ['name' => 'idx_moderation_logs_action_created'],
            )
            ->addIndex(
                ['staff_id', 'created_at'],
                ['name' => 'idx_moderation_logs_staff_created'],
            )
            ->addForeignKey('staff_id', 'staff_users', 'account_id', [
                'delete' => 'RESTRICT',
                'update' => 'RESTRICT',
                'constraint' => 'fk_moderation_logs_staff',
            ])
            ->create();

        $this->table('audit_log', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('actor_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 64])
            ->addColumn('target_type', 'string', ['limit' => 32])
            ->addColumn('target_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('payload_json', 'text', [
                'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_REGULAR,
                'null' => true,
            ])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['created_at'])
            ->addIndex(['action'])
            ->addIndex(['actor_id', 'created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('audit_log')->drop()->save();
        $this->table('moderation_logs')->drop()->save();
        $this->table('site_profile')->drop()->save();
    }
}
