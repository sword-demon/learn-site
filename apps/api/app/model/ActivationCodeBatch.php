<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $course_id
 * @property int $quantity
 * @property string|null $expires_at
 * @property int $created_by_staff_id
 * @property string $created_at
 * @property string $updated_at
 * @method static ActivationCodeBatch|null find(mixed $data = null)
 * @method static ActivationCodeBatch create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class ActivationCodeBatch extends Model
{
    protected string $table = 'activation_code_batches';
    protected string $pk = 'id';
}
