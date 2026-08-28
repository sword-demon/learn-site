<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $staff_user_id
 * @property int $permission_id
 * @property string $effect
 * @property int $actor_account_id
 * @property string|null $reason
 * @property string $created_at
 * @method static StaffPermissionOverride|null find(mixed $data = null)
 * @method static StaffPermissionOverride create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class StaffPermissionOverride extends Model
{
    protected string $table = 'staff_permission_override';
    protected string $pk = 'id';
}
