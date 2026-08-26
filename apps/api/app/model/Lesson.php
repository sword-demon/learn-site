<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Lesson extends Model
{
    protected $table = 'lessons';
    protected $pk = 'id';
}
