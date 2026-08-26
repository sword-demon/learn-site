<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int|null $parent_id
 * @property string $name
 * @property string $path
 * @property int $depth
 * @property int $sort
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @method static Department|null find(mixed $data = null)
 * @method static Department create(array|object $data)
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Department extends Model
{
    protected $table = 'departments';
    protected $pk = 'id';
}
