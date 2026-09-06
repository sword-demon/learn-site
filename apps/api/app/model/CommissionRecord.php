<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $order_id
 * @property int $course_id
 * @property int $referee_learner_id
 * @property int $referrer_learner_id
 * @property int $level
 * @property int $amount_cents
 * @property string $status
 * @property string $source
 * @property string $config_snapshot_json
 * @property int $order_paid_cents_snapshot
 * @property string $created_at
 * @property string|null $settled_at
 * @property string|null $voided_at
 * @property string|null $void_reason
 */
class CommissionRecord extends Model
{
    protected string $table = 'commission_records';
    protected string $pk = 'id';
}
