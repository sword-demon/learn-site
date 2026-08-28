<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $course_id
 * @property int $chapter_id
 * @property string $title
 * @property int $sort
 * @property string $content_type
 * @property string|null $content
 * @property int $is_trial
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @method static Lesson|null find(mixed $data = null)
 * @method static Lesson create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Lesson extends Model
{
    protected string $table = 'lessons';
    protected string $pk = 'id';
}
