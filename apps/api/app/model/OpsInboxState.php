<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

final class OpsInboxState extends Model
{
    protected string $table = 'ops_inbox_state';
    protected string $pk = 'id';
}
