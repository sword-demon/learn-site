<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Chapter extends Model
{
    protected $table = 'chapters';
    protected $pk = 'id';
}
