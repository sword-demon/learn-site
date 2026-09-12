<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * DistributionAuditLog Model
 *
 * 分销审计日志模型 - 记录所有分销相关写操作
 *
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
 * @method static DistributionAuditLog|null find(mixed $data = null)
 * @method static DistributionAuditLog create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class DistributionAuditLog extends Model
{
    protected string $table = 'distribution_audit_log';
    protected string $pk = 'id';

    /**
     * Fields that can be mass assigned
     *
     * @var list<string>
     */
    protected array $field = [
        'id',
        'actor_type',
        'actor_id',
        'action',
        'subject_type',
        'subject_id',
        'before_json',
        'after_json',
        'reason',
        'created_at',
    ];

    /**
     * Type casts
     *
     * @var array<string, string>
     */
    protected array $type = [
        'id' => 'integer',
        'actor_type' => 'string',
        'actor_id' => 'integer|NULL',
        'action' => 'string',
        'subject_type' => 'string',
        'subject_id' => 'integer|NULL',
        'before_json' => 'string|NULL',
        'after_json' => 'string|NULL',
        'reason' => 'string|NULL',
        'created_at' => 'datetime',
    ];
}
