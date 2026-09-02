<?php

declare(strict_types=1);

namespace App\model;

use support\think\Model;

/**
 * @property int $id
 * @property int $batch_id
 * @property int $course_id
 * @property string $code_hash
 * @property string $code_prefix
 * @property string $code_suffix
 * @property string $status
 * @property string|null $expires_at
 * @property int|null $redeemed_by_learner_id
 * @property string|null $redeemed_at
 * @property int|null $voided_by_staff_id
 * @property string|null $voided_at
 * @property string $created_at
 * @property string $updated_at
 * @method static ActivationCode|null find(mixed $data = null)
 * @method static ActivationCode create(array|object $data, array $allowField = [], bool $replace = false, string $suffix = '')
 * @method static \think\db\Query where(mixed $field, mixed $op = null, mixed $condition = null)
 */
final class ActivationCode extends Model
{
    public const STATUS_UNUSED = 'unused';
    public const STATUS_REDEEMED = 'redeemed';
    public const STATUS_VOID = 'void';

    protected string $table = 'activation_codes';
    protected string $pk = 'id';

    /**
     * 过期不是落库状态:未使用且超过 expires_at 即派生为已过期,
     * 兑换与列表筛选时按此刻时间计算(data-model.md 状态机)。
     */
    public function isExpiredAt(int $timestamp): bool
    {
        return $this->expires_at !== null
            && $timestamp >= (int) strtotime((string) $this->expires_at);
    }

    public function isRedeemableStatus(): bool
    {
        return (string) $this->status === self::STATUS_UNUSED;
    }
}
