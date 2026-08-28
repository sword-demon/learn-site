<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $parent_id
 * @property string $name
 * @property string $path
 * @property int $depth
 * @property int $sort
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @method static Category|null find(mixed $data = null)
 * @method static Category create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Category extends Model
{
    protected string $table = 'categories';
    protected string $pk = 'id';
}
