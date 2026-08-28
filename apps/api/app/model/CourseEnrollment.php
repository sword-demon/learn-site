<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $learner_id
 * @property int $course_id
 * @property int $progress_percent
 * @property int|null $last_lesson_id
 * @property int $last_position
 * @property string|null $completed_at
 * @property string $created_at
 * @property string $updated_at
 * @method static CourseEnrollment|null find(mixed $data = null)
 * @method static CourseEnrollment create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class CourseEnrollment extends Model
{
    protected string $table = 'course_enrollments';
    protected string $pk = 'id';
}
