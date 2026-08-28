<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $course_id
 * @property string $title
 * @property int $sort
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @method static Chapter|null find(mixed $data = null)
 * @method static Chapter create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Chapter extends Model
{
    protected string $table = 'chapters';
    protected string $pk = 'id';
}
