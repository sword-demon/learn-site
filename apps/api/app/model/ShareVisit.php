<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * ShareVisit Model
 *
 * 访问痕迹模型 - 记录访客通过分享链接的访问行为
 *
 * @property int $id
 * @property int $share_entry_id
 * @property string $visitor_token
 * @property string $visited_at
 * @property int|null $bound_learner_id
 * @property string|null $bound_at
 * @method static ShareVisit|null find(mixed $data = null)
 * @method static ShareVisit create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class ShareVisit extends Model
{
    protected string $table = 'share_visits';
    protected string $pk = 'id';

    /**
     * Fields that can be mass assigned
     *
     * @var list<string>
     */
    protected array $field = [
        'id',
        'share_entry_id',
        'visitor_token',
        'visited_at',
        'bound_learner_id',
        'bound_at',
    ];

    /**
     * Type casts
     *
     * @var array<string, string>
     */
    protected array $type = [
        'id' => 'integer',
        'share_entry_id' => 'integer',
        'visitor_token' => 'string',
        'visited_at' => 'datetime',
        'bound_learner_id' => 'integer|NULL',
        'bound_at' => 'datetime|NULL',
    ];
}
