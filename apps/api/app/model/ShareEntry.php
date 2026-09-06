<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $learner_id
 * @property string $scope
 * @property int|null $course_id
 * @property string $short_code
 * @property bool $distribution_enabled_at_creation
 * @property string $created_at
 * @property string|null $revoked_at
 * @method static ShareEntry|null find(mixed $data = null)
 * @method static ShareEntry create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class ShareEntry extends Model
{
    protected string $table = 'share_entries';
    protected string $pk = 'id';
}
