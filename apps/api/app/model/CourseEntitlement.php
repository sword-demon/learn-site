<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $learner_id
 * @property int $course_id
 * @property string $source
 * @property int|null $order_id
 * @property string $status
 * @property string|null $revoked_at
 * @property string|null $revoked_reason
 * @property int|null $revoked_by_staff_id
 * @property string $created_at
 * @property string $updated_at
 * @method static CourseEntitlement|null find(mixed $data = null)
 * @method static CourseEntitlement create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class CourseEntitlement extends Model
{
    protected string $table = 'course_entitlements';
    protected string $pk = 'id';

    public function isRevocable(): bool
    {
        return (string) $this->source === 'free' && (string) $this->status === 'active';
    }
}
