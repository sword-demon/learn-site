<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $learner_id
 * @property int $lesson_id
 * @property int $position_seconds
 * @property int $completed
 * @property string|null $opened_at
 * @property string|null $completed_at
 * @property string $created_at
 * @property string $updated_at
 * @method static LessonProgress|null find(mixed $data = null)
 * @method static LessonProgress create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class LessonProgress extends Model
{
    protected string $table = 'lesson_progresses';
    protected string $pk = 'id';
}
