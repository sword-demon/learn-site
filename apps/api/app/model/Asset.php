<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Asset extends Model
{
    protected $table = 'assets';
    protected $pk = 'id';
}
