<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Phase 17 / US7 — favorites + share_posters.
 *
 * - favorites: (learner_id, course_id) unique; one-tap toggle, idempotent.
 * - share_posters: a render snapshot for share links; failure to render
 *   leaves the share URL alive (background retry can fill it in).
 *
 * No foreign keys to courses table (catalog migration runs first; this
 * migration was sequenced after maps, but the catalog migration created
 * the courses table earlier — FK is set explicitly).
 */
final class CreateShare extends AbstractMigration
{
    public function change(): void
    {
        $this->table('favorites', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('learner_id', 'biginteger', ['signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['learner_id', 'course_id'], ['unique' => true])
            ->addIndex(['course_id'])
            ->create();

        $this->table('share_posters', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('token', 'string', ['limit' => 64])
            ->addColumn('cover_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('title_snapshot', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('teacher_snapshot', 'string', ['limit' => 64, 'null' => true])
            ->addColumn('price_snapshot', 'decimal', ['precision' => 10, 'scale' => 2, 'null' => true])
            ->addColumn('render_status', 'enum', ['values' => ['pending', 'ready', 'failed'], 'default' => 'pending'])
            ->addColumn('created_at', 'datetime')
            ->addIndex(['token'], ['unique' => true])
            ->addIndex(['course_id'])
            ->create();
    }
}