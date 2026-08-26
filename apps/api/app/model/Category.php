<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Category extends Model
{
    protected $table = 'categories';
    protected $pk = 'id';
}
