<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $account_id
 * @property int $is_super_admin
 * @property int|null $department_id
 * @property string $display_name
 * @property string $created_at
 * @property string $updated_at
 * @method static StaffUser|null find(mixed $data = null)
 * @method static StaffUser create(array|object $data)
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class StaffUser extends Model
{
    protected string $table = 'staff_users';
    protected string $pk = 'account_id';
}
