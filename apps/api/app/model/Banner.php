<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;
use think\model\concern\SoftDelete;

/**
 * Site-wide banner. SoftDelete keeps deleted rows out of normal model
 * queries while allowing operational code to use withTrashed().
 */
class Banner extends Model
{
    use SoftDelete;

    protected string $table = 'banners';
    protected string $pk = 'id';
    protected string $deleteTime = 'deleted_at';
}
