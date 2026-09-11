<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $course_id
 * @property int $enabled
 * @property float|null $level1_pct
 * @property float|null $level2_pct
 * @property float|null $level3_pct
 * @property int|null $per_order_cap_cents
 * @property int|null $per_learner_course_cap_cents
 * @property int $updated_by_staff_id
 * @property string $updated_at
 */
class DistributionCourseOverride extends Model
{
    protected string $table = 'distribution_course_overrides';
    protected string $pk = 'course_id';
}
