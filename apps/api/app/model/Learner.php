<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $account_id
 * @property int|null $referrer_learner_id
 * @property string|null $nickname
 * @property string|null $avatar_url
 * @property int $show_on_course
 * @property string $created_at
 * @property string $updated_at
 * @method static Learner|null find(mixed $data = null)
 * @method static Learner create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Learner extends Model
{
    protected string $table = 'learners';
    protected string $pk = 'account_id';
}
