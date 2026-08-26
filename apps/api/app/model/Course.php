<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Course extends Model
{
    protected $table = 'courses';
    protected $pk = 'id';
}
