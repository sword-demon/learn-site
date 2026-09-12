<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * DistributionCourseOverride Model
 *
 * 课程级分销覆盖模型 - 为单课配置独立的佣金比例和封顶
 *
 * @property int $course_id
 * @property bool $enabled
 * @property float|null $level1_pct
 * @property float|null $level2_pct
 * @property float|null $level3_pct
 * @property int|null $per_order_cap_cents
 * @property int|null $per_learner_course_cap_cents
 * @property int $updated_by_staff_id
 * @property string $updated_at
 * @method static DistributionCourseOverride|null find(mixed $data = null)
 * @method static DistributionCourseOverride create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class DistributionCourseOverride extends Model
{
    protected string $table = 'distribution_course_overrides';
    protected string $pk = 'course_id'; // Primary key is course_id

    /**
     * Fields that can be mass assigned
     *
     * @var list<string>
     */
    protected array $field = [
        'course_id',
        'enabled',
        'level1_pct',
        'level2_pct',
        'level3_pct',
        'per_order_cap_cents',
        'per_learner_course_cap_cents',
        'updated_by_staff_id',
        'updated_at',
    ];

    /**
     * Type casts
     *
     * @var array<string, string>
     */
    protected array $type = [
        'course_id' => 'integer',
        'enabled' => 'boolean',
        'level1_pct' => 'double|NULL',
        'level2_pct' => 'double|NULL',
        'level3_pct' => 'double|NULL',
        'per_order_cap_cents' => 'integer|NULL',
        'per_learner_course_cap_cents' => 'integer|NULL',
        'updated_by_staff_id' => 'integer',
        'updated_at' => 'datetime',
    ];
}
