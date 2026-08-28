<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $learner_id
 * @property int $course_id
 * @property float $list_price_snapshot
 * @property float $sale_price_snapshot
 * @property float $paid_amount
 * @property string $currency
 * @property string $status
 * @property string $provider
 * @property string|null $provider_ref
 * @property string|null $succeeded_at
 * @property string $created_at
 * @property string $updated_at
 * @method static Order|null find(mixed $data = null)
 * @method static Order create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class Order extends Model
{
    protected string $table = 'orders';
    protected string $pk = 'id';
}
