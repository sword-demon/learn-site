<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $kind
 * @property string $storage_path
 * @property string $mime_type
 * @property int $size_bytes
 * @property string $status
 * @property int $created_by_staff_id
 * @property string $created_at
 * @property string $updated_at
 * @method static Asset|null find(mixed $data = null)
 * @method static Asset create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Asset extends Model
{
    protected string $table = 'assets';
    protected string $pk = 'id';
}
