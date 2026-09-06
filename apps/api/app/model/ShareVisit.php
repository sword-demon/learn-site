<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $share_entry_id
 * @property string $visitor_token
 * @property string|null $ip
 * @property string|null $user_agent
 * @property int|null $bound_learner_id
 * @property string|null $bound_at
 * @property string $created_at
 */
class ShareVisit extends Model
{
    protected string $table = 'share_visits';
    protected string $pk = 'id';
}
