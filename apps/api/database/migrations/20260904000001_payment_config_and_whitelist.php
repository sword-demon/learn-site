<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class PaymentConfigAndWhitelist extends AbstractMigration
{
    public function up(): void
    {
        if (!$this->hasTable('payment_config')) {
            $this->table('payment_config', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'integer', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY,
                    'default' => 1,
                    'null' => false,
                ])
                ->addColumn('api_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('pid', 'string', ['limit' => 64, 'null' => false])
                ->addColumn('merchant_key_cipher', 'text', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM,
                    'null' => false,
                ])
                ->addColumn('notify_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('return_url', 'string', ['limit' => 255, 'null' => false])
                ->addColumn('enabled_channels', 'json', ['null' => false])
                ->addColumn('enabled', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('whitelist_only', 'boolean', ['default' => false, 'null' => false])
                ->addColumn('version', 'integer', [
                    'limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_REGULAR,
                    'default' => 1,
                    'null' => false,
                    'signed' => false,
                ])
                ->addColumn('created_at', 'datetime', ['null' => true])
                ->addColumn('updated_at', 'datetime', ['null' => true])
                ->addColumn('updated_by_staff_id', 'biginteger', [
                    'signed' => false,
                    'null' => true,
                ])
                ->addForeignKey('updated_by_staff_id', 'staff_users', 'account_id', [
                    'delete' => 'RESTRICT',
                    'update' => 'RESTRICT',
                    'constraint' => 'fk_payment_config_updated_by_staff',
                ])
                ->create();
            $this->execute(
                'ALTER TABLE payment_config '
                . 'ADD CONSTRAINT chk_payment_config_singleton CHECK (id = 1)',
            );
        }

        if (!$this->hasTable('payment_whitelist')) {
            $this->table('payment_whitelist', ['id' => false, 'primary_key' => ['id']])
                ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
                ->addColumn('phone', 'string', ['limit' => 11, 'null' => false])
                ->addColumn('enabled', 'boolean', ['default' => true, 'null' => false])
                ->addColumn('note', 'string', ['limit' => 120, 'null' => true])
                ->addColumn('created_by', 'biginteger', ['signed' => false, 'null' => false])
                ->addColumn('created_at', 'datetime', ['null' => false])
                ->addColumn('updated_at', 'datetime', ['null' => false])
                ->addColumn('deleted_at', 'datetime', ['null' => true])
                ->addForeignKey('created_by', 'staff_users', 'account_id', [
                    'delete' => 'RESTRICT',
                    'update' => 'CASCADE',
                    'constraint' => 'fk_payment_whitelist_created_by',
                ])
                ->addIndex(['phone', 'deleted_at'], [
                    'unique' => true,
                    'name' => 'uk_payment_whitelist_phone',
                ])
                ->addIndex(['enabled', 'deleted_at'], ['name' => 'idx_payment_whitelist_enabled'])
                ->create();

            // MySQL permits duplicate NULLs in a composite unique index, so
            // (phone, deleted_at) alone cannot enforce one active row per phone.
            $this->execute(
                "ALTER TABLE payment_whitelist
                 ADD COLUMN active_phone VARCHAR(11)
                 GENERATED ALWAYS AS (IF(deleted_at IS NULL, phone, NULL)) STORED
                 AFTER deleted_at,
                 ADD UNIQUE KEY uk_payment_whitelist_active (active_phone)",
            );
        }
    }

    public function down(): void
    {
        if ($this->hasTable('payment_whitelist')) {
            $this->table('payment_whitelist')->drop()->save();
        }
        if ($this->hasTable('payment_config')) {
            $this->table('payment_config')->drop()->save();
        }
    }
}
