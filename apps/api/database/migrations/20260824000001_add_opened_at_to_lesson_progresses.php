<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/** Add the open timestamp required by explicit Markdown/PDF completion. */
final class AddOpenedAtToLessonProgresses extends AbstractMigration
{
    public function change(): void
    {
        $this->table('lesson_progresses')
            ->addColumn('opened_at', 'datetime', ['null' => true, 'after' => 'position_seconds'])
            ->update();
    }
}
