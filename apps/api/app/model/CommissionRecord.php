<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * CommissionRecord Model
 *
 * 佣金记录模型 - 每行代表一笔待结算/已结算/已撤销的佣金
 *
 * @property int $id
 * @property int $order_id
 * @property int $referrer_learner_id
 * @property int $referee_learner_id
 * @property int $level
 * @property int $amount_cents
 * @property string $status
 * @property string $source
 * @property string $config_snapshot_json
 * @property int $order_paid_cents_snapshot
 * @property string $created_at
 * @property string|null $settled_at
 * @property string|null $voided_at
 * @property int|null $voided_by
 * @property string|null $void_reason
 * @method static CommissionRecord|null find(mixed $data = null)
 * @method static CommissionRecord create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class CommissionRecord extends Model
{
    protected string $table = 'commission_records';
    protected string $pk = 'id';

    /**
     * Fields that can be mass assigned
     *
     * @var list<string>
     */
    protected array $field = [
        'id',
        'order_id',
        'referrer_learner_id',
        'referee_learner_id',
        'level',
        'amount_cents',
        'status',
        'source',
        'config_snapshot_json',
        'order_paid_cents_snapshot',
        'created_at',
        'settled_at',
        'voided_at',
        'voided_by',
        'void_reason',
    ];

    /**
     * Type casts
     *
     * @var array<string, string>
     */
    protected array $type = [
        'id' => 'integer',
        'order_id' => 'integer',
        'referrer_learner_id' => 'integer',
        'referee_learner_id' => 'integer',
        'level' => 'integer',
        'amount_cents' => 'integer',
        'status' => 'string',
        'source' => 'string',
        'config_snapshot_json' => 'string',
        'order_paid_cents_snapshot' => 'integer',
        'created_at' => 'datetime',
        'settled_at' => 'datetime|NULL',
        'voided_at' => 'datetime|NULL',
        'voided_by' => 'integer|NULL',
        'void_reason' => 'string|NULL',
    ];
}
