<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $kind
 * @property string $login
 * @property string $password_hash
 * @property int $must_change_password
 * @property string $status
 * @property string|null $last_login_at
 * @property string $created_at
 * @property string $updated_at
 * @method static Account|null find(mixed $data = null)
 * @method static Account create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Account extends Model
{
    protected string $table = 'accounts';
    protected string $pk = 'id';
}
