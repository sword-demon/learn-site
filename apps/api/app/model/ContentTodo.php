<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $source_type
 * @property string $source_key
 * @property int|null $source_course_id
 * @property int|null $target_course_id
 * @property int|null $target_chapter_id
 * @property int|null $target_lesson_id
 * @property string|null $label
 * @property string $workflow_status
 * @property string|null $first_response_at
 * @property string|null $first_response_kind
 * @property int|null $first_response_notification_id
 * @property string|null $result_type
 * @property string|null $close_reason_code
 * @property string|null $close_reason_note
 * @property int|null $resolved_by_staff_id
 * @property string|null $resolved_at
 * @property int $version
 * @property string $created_at
 * @property string $updated_at
 */
final class ContentTodo extends Model
{
    public const SOURCE_QUESTION_PENDING = 'question_pending';
    public const SOURCE_FEEDBACK_PENDING = 'feedback_pending';

    public const STATUS_UNTRIAGED = 'untriaged';
    public const STATUS_TRIAGED = 'triaged';
    public const STATUS_AWAITING_APPROVAL = 'awaiting_approval';
    public const STATUS_RESOLVED = 'resolved';
    public const STATUS_CLOSED = 'closed';

    public const LABEL_ERROR = 'error';
    public const LABEL_MISSING_EXAMPLE = 'missing_example';
    public const LABEL_RESOURCE_PROBLEM = 'resource_problem';
    public const LABEL_OTHER = 'other';

    protected string $table = 'content_todos';
    protected string $pk = 'id';
}
