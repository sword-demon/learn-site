<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property string $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property string $subject_type
 * @property int|null $subject_id
 * @property string|null $before_json
 * @property string|null $after_json
 * @property string|null $reason
 * @property string $created_at
 */
class DistributionAuditLog extends Model
{
    protected string $table = 'distribution_audit_log';
    protected string $pk = 'id';
}
