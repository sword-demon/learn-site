<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $content_todo_id
 * @property int $version
 * @property int|null $target_course_id
 * @property int|null $target_chapter_id
 * @property int|null $target_lesson_id
 * @property string $target_kind
 * @property string $body
 * @property string $body_format
 * @property string $base_content_fingerprint
 * @property string $generator
 * @property string $status
 * @property int|null $generated_by_staff_id
 * @property string $generated_at
 * @property int|null $approved_by_staff_id
 * @property string|null $approved_at
 * @property int|null $rejected_by_staff_id
 * @property string|null $rejected_at
 * @property string|null $rejection_reason
 * @property string $created_at
 * @property string $updated_at
 */
final class ContentTodoCandidate extends Model
{
    public const TARGET_COURSE_INTRO = 'course_intro';
    public const TARGET_LESSON_MARKDOWN = 'lesson_markdown';
    public const TARGET_HELP_CENTER = 'help_center_candidate';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';

    protected string $table = 'content_todo_candidates';
    protected string $pk = 'id';
}
