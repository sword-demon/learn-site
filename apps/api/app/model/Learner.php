<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

class Learner extends Model
{
    protected $table = 'learners';
    protected $pk = 'account_id';
}
