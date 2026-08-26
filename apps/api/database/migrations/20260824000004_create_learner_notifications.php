<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 21 / US18 — learner notifications (T104).
 *
 *   learner_notifications: in-app inbox rows. The transport here is the
 *   table itself; a future mailer / push pipeline will consume unread rows
 *   on a schedule. We do NOT pull Redis into the notification path —
 *   Redis is tokens / captcha / revocation only (Constitution IV).
 */
final class CreateLearnerNotifications extends AbstractMigration
{
    public function change(): void
    {
        $this->table('learner_notifications', [
            'id' => false,
            'primary_key' => ['id'],
        ])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('kind', 'string', ['limit' => 64])
            ->addColumn('title', 'string', ['limit' => 200])
            ->addColumn('body', 'text', ['null' => true])
            ->addColumn('payload_json', 'text', ['null' => true])
            ->addColumn('read_at', 'timestamp', ['null' => true])
            ->addColumn('created_at', 'timestamp', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['learner_id', 'read_at'])
            ->addIndex(['kind'])
            ->create();
    }
}
