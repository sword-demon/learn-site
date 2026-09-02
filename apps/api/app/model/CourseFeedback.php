<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $course_id
 * @property int $learner_id
 * @property string $body_html
 * @property string $status
 * @property int|null $processed_by_staff_id
 * @property string|null $processed_at
 * @property string $created_at
 * @property string $updated_at
 * @method static CourseFeedback|null find(mixed $data = null)
 * @method static CourseFeedback create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class CourseFeedback extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSED = 'processed';

    protected string $table = 'course_feedbacks';
    protected string $pk = 'id';
}
