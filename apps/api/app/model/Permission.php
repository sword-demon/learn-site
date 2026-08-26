<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Permission extends Model
{
    protected $table = 'permissions';
    protected $pk = 'id';
}
