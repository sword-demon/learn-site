<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Catalog schema (Phase 4 — User Story 2).
 *
 *  - categories:  ≤3 levels, status enabled/disabled.
 *  - courses:     draft / published / unpublished. Owned by a department
 *                 (data-scope anchor) and a category. Sanitised rich-text
 *                 intro lives here. Free / paid with sale window.
 *  - chapters:    flat list under a course.
 *  - lessons:     three content types — markdown / pdf / video. PDF and
 *                 video lessons point at an asset row (storage_path on
 *                 disk, status='ready' in Phase 4, real worker in Phase 9).
 *  - assets:      uploaded files (PDF / video). storage_path is the disk
 *                 location; mime + size are recorded for delivery.
 *
 * FK on delete policy:
 *   categories ← courses       RESTRICT  (cannot delete an in-use category;
 *                                       Phase 4 controller also enforces
 *                                       CATEGORY_IN_USE for published rows)
 *   courses    ← chapters      CASCADE
 *   chapters   ← lessons       CASCADE
 *   assets     ← lessons       RESTRICT  (deleting an asset must be a
 *                                       conscious admin act, never an
 *                                       accident from a course delete)
 */
final class CreateCatalog extends AbstractMigration
{
    public function change(): void
    {
        // categories — up to 3 levels, parent_id 0 = root (we use BIGINT
        // unsigned so 0 is reserved as "no parent"; nullable would also
        // work but 0 keeps joins simple and matches the seeded data path).
        $this->table('categories', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('parent_id', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('path', 'string', ['limit' => 255, 'default' => '/'])
            ->addColumn('depth', 'integer', ['default' => 1])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['parent_id'])
            ->addIndex(['path'])
            ->addIndex(['status'])
            ->addIndex(['parent_id', 'name'], ['unique' => true])
            ->create();

        // courses — owned by a department (data-scope root) and a category.
        $this->table('courses', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('department_id', 'biginteger', ['signed' => false])
            ->addColumn('category_id', 'biginteger', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 128])
            ->addColumn('cover_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('teacher_name', 'string', ['limit' => 64])
            ->addColumn('summary', 'string', ['limit' => 255])
            ->addColumn('intro_rich_text', 'text', ['null' => true])
            ->addColumn('status', 'enum', ['values' => ['draft', 'published', 'unpublished'], 'default' => 'draft'])
            ->addColumn('price_mode', 'enum', ['values' => ['free', 'paid'], 'default' => 'free'])
            ->addColumn('list_price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('sale_price', 'decimal', ['precision' => 10, 'scale' => 2, 'default' => 0])
            ->addColumn('sale_start_at', 'datetime', ['null' => true])
            ->addColumn('sale_end_at', 'datetime', ['null' => true])
            ->addColumn('created_by_staff_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('department_id', 'departments', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('category_id', 'categories', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addForeignKey('created_by_staff_id', 'staff_users', 'account_id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['status', 'category_id'])
            ->addIndex(['department_id'])
            ->create();

        // chapters — flat list under a course.
        $this->table('chapters', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('course_id', 'biginteger', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 128])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('course_id', 'courses', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addIndex(['course_id', 'sort'])
            ->create();

        // assets — uploaded files. Status values:
        //   processing (worker picked up) → ready (worker done)
        //   missing (file disappeared) / broken (worker failed)
        // Phase 4 always lands rows as 'ready'; worker lands in Phase 9.
        $this->table('assets', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('kind', 'enum', ['values' => ['pdf', 'video']])
            ->addColumn('storage_path', 'string', ['limit' => 512])
            ->addColumn('mime_type', 'string', ['limit' => 64])
            ->addColumn('size_bytes', 'biginteger', ['signed' => false, 'default' => 0])
            ->addColumn('status', 'enum', ['values' => ['processing', 'ready', 'missing', 'broken'], 'default' => 'processing'])
            ->addColumn('created_by_staff_id', 'biginteger', ['signed' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('created_by_staff_id', 'staff_users', 'account_id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['kind'])
            ->create();

        // lessons — markdown / pdf / video. markdown stores body_markdown;
        // pdf and video store an asset_id. content_type is NOT NULL; the
        // matching payload column must be non-empty for the row to count
        // toward a publishable course (enforced in CourseService).
        $this->table('lessons', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('chapter_id', 'biginteger', ['signed' => false])
            ->addColumn('title', 'string', ['limit' => 128])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('content_type', 'enum', ['values' => ['markdown', 'pdf', 'video']])
            ->addColumn('body_markdown', 'text', ['null' => true])
            ->addColumn('asset_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('is_preview', 'boolean', ['default' => false])
            ->addColumn('duration_seconds', 'integer', ['default' => 0])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('chapter_id', 'chapters', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('asset_id', 'assets', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->addIndex(['chapter_id', 'sort'])
            ->addIndex(['content_type'])
            ->create();
    }
}
