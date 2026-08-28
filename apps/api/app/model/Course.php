<?php
declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $department_id
 * @property int $category_id
 * @property string $title
 * @property string|null $cover_url
 * @property string $teacher_name
 * @property string $summary
 * @property string $intro_rich_text
 * @property string $status
 * @property string $price_mode
 * @property float $list_price
 * @property float $sale_price
 * @property string|null $sale_start_at
 * @property string|null $sale_end_at
 * @property int $created_by_staff_id
 * @property string $created_at
 * @property string $updated_at
 * @method static Course|null find(mixed $data = null)
 * @method static Course create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
class Course extends Model
{
    protected string $table = 'courses';
    protected string $pk = 'id';
}
