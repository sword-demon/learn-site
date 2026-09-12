<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class CreateApiRequestAuditLog extends AbstractMigration
{
    public function up(): void
    {
        $this->table('api_request_audit_log', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('request_method', 'string', ['limit' => 10])
            ->addColumn('route_path', 'string', ['limit' => 255])
            ->addColumn('request_params', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('response_data', 'text', ['null' => true, 'limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM])
            ->addColumn('response_status', 'integer', ['limit' => 3, 'signed' => false])
            ->addColumn('duration_ms', 'integer', ['limit' => 4, 'signed' => false])
            ->addColumn('actor_id', 'biginteger', ['null' => true, 'signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['created_at'])
            ->addIndex(['request_method', 'created_at'])
            ->create();
    }

    public function down(): void
    {
        $this->table('api_request_audit_log')->drop()->save();
    }
}
