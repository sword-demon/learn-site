<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * ShareEntry Model
 *
 * 学员分享入口模型 - 每行代表一个可分享的短码链接
 *
 * @property int $id
 * @property int $learner_id
 * @property string $short_code
 * @property string|null $short_code_encrypted
 * @property string $scope
 * @property int|null $course_id
 * @property string $created_at
 * @property bool $distribution_enabled_at_creation
 * @property string|null $revoked_at
 * @method static ShareEntry|null find(mixed $data = null)
 * @method static ShareEntry create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class ShareEntry extends Model
{
    protected string $table = 'share_entries';
    protected string $pk = 'id';

    /**
     * Fields that can be mass assigned
     *
     * @var list<string>
     */
    protected array $field = [
        'id',
        'learner_id',
        'short_code',
        'short_code_encrypted',
        'scope',
        'course_id',
        'created_at',
        'distribution_enabled_at_creation',
        'revoked_at',
    ];

    /**
     * Type casts
     *
     * @var array<string, string>
     */
    protected array $type = [
        'id' => 'integer',
        'learner_id' => 'integer',
        'scope' => 'string',
        'course_id' => 'integer|NULL',
        'created_at' => 'datetime',
        'distribution_enabled_at_creation' => 'boolean',
        'revoked_at' => 'datetime|NULL',
    ];
}
