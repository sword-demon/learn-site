<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $status
 * @method static Permission|null find(mixed $data = null)
 * @method static Permission create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Permission extends Model
{
    protected string $table = 'permissions';
    protected string $pk = 'id';
}
