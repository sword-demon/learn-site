<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $name
 * @property string $code
 * @property string $data_scope
 * @property string $status
 * @property string $created_at
 * @property string $updated_at
 * @method static Role|null find(mixed $data = null)
 * @method static Role create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Role extends Model
{
    protected string $table = 'roles';
    protected string $pk = 'id';
}
