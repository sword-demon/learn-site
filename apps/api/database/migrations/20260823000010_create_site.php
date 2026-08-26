<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 19 / US11 — public site profile + audit log (T094).
 *
 * site_profile: a single-row table (id=1) for the public-facing site intro.
 *   - title / subtitle / body_html are admin-editable and rendered on the
 *     learner home (T097).
 *   - body_html is server-sanitised by the existing HtmlSanitizer; we still
 *     cap the stored length to 4000 chars.
 *
 * audit_log: append-only moderation log. Inserted via Logger::audit()
 * from services that need a permanent, queryable paper trail
 * (FR-093's structured stdout log is the runtime paper trail; this is the
 * admin-visible one).
 */
final class CreateSite extends AbstractMigration
{
    public function change(): void
    {
        $this->table('site_profile', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'integer', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::INT_TINY, 'default' => 1, 'null' => false])
            ->addColumn('title', 'string', ['limit' => 80, 'default' => '学习平台'])
            ->addColumn('subtitle', 'string', ['limit' => 160, 'default' => '选课、学习、交流'])
            ->addColumn('body_html', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_MEDIUM, 'null' => true])
            ->addColumn('contact_email', 'string', ['limit' => 120, 'default' => ''])
            ->addColumn('updated_by_staff_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('updated_at', 'datetime', ['null' => true])
            ->create();

        $this->table('audit_log', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('actor_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('action', 'string', ['limit' => 64])
            ->addColumn('target_type', 'string', ['limit' => 32])
            ->addColumn('target_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('payload_json', 'text', ['limit' => \Phinx\Db\Adapter\MysqlAdapter::TEXT_REGULAR, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['created_at'])
            ->addIndex(['action'])
            ->addIndex(['actor_id', 'created_at'])
            ->create();
    }
}
