<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;

/**
 * 优惠券领域服务 — Phase 9（009-learner-coupons）。
 *
 * 职责：
 *  - coupon_campaigns 及适用范围关联表（管理端 CRUD）
 *  - learner_coupons（实例生命周期：unused → locked → used|expired|voided）
 *  - lockForOrder / redeemOnSuccess / releaseOnTerminal（由 OrderService 调用）
 *
 * 业务不变量（schema 层尽可能约束）：
 *  1. 配额计数仅存 MySQL — 每次递增前 SELECT … FOR UPDATE，确保不超过 total_quota
 *     （宪章：Redis 不承载配额）。
 *  2. used_order_id 有唯一索引 — 单张券实例只能被一笔成功订单核销；
 *     支付回调可重投，对已核销实例应幂等 no-op。
 *  3. lockForOrder 与订单行插入在同一事务内，外层事务由 OrderService 编排。
 *  4. 管理端写操作均通过 writeAudit() 写入 audit_log（action 码见 data-model.md，H3）。
 */
final class CouponService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const DEFAULT_CLAIM_LIMIT = 1;
    private const DEFAULT_USE_LIMIT = 1;
    private const DEFAULT_TOTAL_QUOTA = 10000;
    private const MAX_NAME_LENGTH = 120;
    private const MAX_GRANT_BATCH = 500;
    private const MAX_PAGE_LIMIT = 200;

    /** 适用范围：全站 */
    public const SCOPE_ALL = 'all';
    /** 适用范围：指定分类（含子树） */
    public const SCOPE_CATEGORY = 'category';
    /** 适用范围：指定课程 */
    public const SCOPE_COURSE = 'course';

    /** 活动状态：进行中 */
    public const STATUS_ACTIVE = 'active';
    /** 活动状态：已停用 */
    public const STATUS_DISABLED = 'disabled';

    /** 实例状态：未使用 */
    public const INSTANCE_UNUSED = 'unused';
    /** 实例状态：已锁定（待支付订单占用） */
    public const INSTANCE_LOCKED = 'locked';
    /** 实例状态：已核销 */
    public const INSTANCE_USED = 'used';
    /** 实例状态：已过期 */
    public const INSTANCE_EXPIRED = 'expired';
    /** 实例状态：已作废（活动停用级联） */
    public const INSTANCE_VOIDED = 'voided';

    /** 来源：学员自助领取 */
    public const SOURCE_CLAIM = 'claim';
    /** 来源：管理端定向发放 */
    public const SOURCE_GRANT = 'grant';

    /** 领取方式：公开领取 */
    public const CLAIM_PUBLIC = 'public';
    /** 领取方式：仅管理端发放 */
    public const CLAIM_ADMIN_ONLY = 'admin_only';

    // -------------------------------------------------------------------------
    // 活动 CRUD（管理端）
    // -------------------------------------------------------------------------

    /**
     * 创建优惠券活动。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCampaign(array $input, int $staffId): array
    {
        $this->assertActor($staffId);
        $payload = $this->validateCampaignInput($input, true);

        $id = Db::transaction(function () use ($payload, $staffId) {
            $now = $this->nowDatetime();
            $campaignId = (int) Db::name('coupon_campaigns')->insertGetId([
                'name' => $payload['name'],
                'scope_type' => $payload['scope_type'],
                'min_amount' => $payload['min_amount'],
                'discount_amount' => $payload['discount_amount'],
                'claim_mode' => $payload['claim_mode'],
                'claim_starts_at' => $payload['claim_starts_at'],
                'claim_ends_at' => $payload['claim_ends_at'],
                'use_ends_at' => $payload['use_ends_at'],
                'total_quota' => $payload['total_quota'],
                'claimed_count' => 0,
                'used_count' => 0,
                'per_learner_claim_limit' => $payload['per_learner_claim_limit'],
                'per_learner_use_limit' => $payload['per_learner_use_limit'],
                'status' => self::STATUS_ACTIVE,
                'created_by' => $staffId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->insertScopeJunctions($campaignId, $payload);
            return $campaignId;
        });

        $this->writeAudit($staffId, 'coupon.create', $id, [
            'name' => $payload['name'],
            'scope_type' => $payload['scope_type'],
            'discount_amount' => $payload['discount_amount'],
            'total_quota' => $payload['total_quota'],
        ]);

        return $this->shapeAdminCampaign($this->loadCampaignRow($id));
    }

    /**
     * 按乐观锁更新活动；领取前/进行中/已结束各阶段可改字段不同。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function patchCampaign(int $campaignId, array $input, int $staffId): array
    {
        $this->assertActor($staffId);
        $row = $this->loadCampaignRow($campaignId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'COUPON_NOT_FOUND');
        }
        $expectedUpdatedAt = $this->validateExpectedUpdatedAt(
            $input['expected_updated_at'] ?? null,
        );

        $now = $this->nowDatetime();
        $started = isset($row['claim_starts_at'])
            && $this->sqlDatetimeTimestamp((string) $row['claim_starts_at']) <= $this->nowTimestamp();
        $ended = isset($row['claim_ends_at'])
            && $this->sqlDatetimeTimestamp((string) $row['claim_ends_at']) <= $this->nowTimestamp();

        $updates = [];
        if (array_key_exists('name', $input)) {
            $name = $this->validateName($input['name']);
            $updates['name'] = $name;
        }
        if (array_key_exists('claim_ends_at', $input)) {
            $claimEndsAt = $this->validateIso8601ToSql($input['claim_ends_at'], 'claim_ends_at');
            if ($this->sqlDatetimeTimestamp($claimEndsAt) <= $this->sqlDatetimeTimestamp((string) $row['claim_starts_at'])) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
            }
            $updates['claim_ends_at'] = $claimEndsAt;
        }
        if (array_key_exists('use_ends_at', $input)) {
            $useEndsAt = $input['use_ends_at'] === null
                ? null
                : $this->validateIso8601ToSql($input['use_ends_at'], 'use_ends_at');
            if ($useEndsAt !== null) {
                $base = $updates['claim_ends_at'] ?? (string) $row['claim_ends_at'];
                if ($this->sqlDatetimeTimestamp($useEndsAt) < $this->sqlDatetimeTimestamp($base)) {
                    throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
                }
            }
            $updates['use_ends_at'] = $useEndsAt;
        }
        if (array_key_exists('total_quota', $input)) {
            $quota = $this->validateTotalQuota($input['total_quota']);
            $claimed = (int) $row['claimed_count'];
            if ($quota !== null && $claimed > $quota) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
            }
            $updates['total_quota'] = $quota;
        }

        if ($updates === []) {
            throw new BusinessException('VALIDATION_FAILED', 'EMPTY_UPDATE');
        }
        if (!$started && !$ended) {
            // 领取开始前：除已校验字段外均可修改。
        } elseif ($started && !$ended) {
            // 领取进行中：仅允许白名单字段；total_quota 不可下调。
            $allowed = ['name', 'claim_ends_at', 'use_ends_at', 'total_quota'];
            $diff = array_diff(array_keys($updates), $allowed);
            if ($diff !== []) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
            }
            if (isset($updates['total_quota'])) {
                $newQuota = $updates['total_quota'];
                $oldQuota = $row['total_quota'] !== null ? (int) $row['total_quota'] : null;
                if ($newQuota !== null && $oldQuota !== null && $newQuota < $oldQuota) {
                    throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
                }
            }
        } else {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }

        $updates['updated_at'] = $this->nextUpdatedAt((string) $row['updated_at']);
        $updated = Db::name('coupon_campaigns')
            ->where('id', $campaignId)
            ->where('updated_at', $expectedUpdatedAt)
            ->update($updates);
        if ($updated !== 1) {
            throw new BusinessException('CONFLICT', 'COUPON_VERSION_CONFLICT');
        }

        $this->writeAudit($staffId, 'coupon.update', $campaignId, $updates);

        return $this->shapeAdminCampaign($this->loadCampaignRow($campaignId));
    }

    /** 停用活动；未使用/已锁定的实例级联作废。 @return array<string, mixed> */
    public function disableCampaign(int $campaignId, int $staffId): array
    {
        $this->assertActor($staffId);
        $row = $this->loadCampaignRow($campaignId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'COUPON_NOT_FOUND');
        }
        if ((string) $row['status'] === self::STATUS_DISABLED) {
            return $this->shapeAdminCampaign($row);
        }

        $now = $this->nowDatetime();
        Db::transaction(function () use ($campaignId, $row, $now) {
            Db::name('coupon_campaigns')
                ->where('id', $campaignId)
                ->where('status', self::STATUS_ACTIVE)
                ->update([
                    'status' => self::STATUS_DISABLED,
                    'updated_at' => $now,
                ]);
            // 级联：unused / locked → voided（已使用的保留历史记录）
            Db::name('learner_coupons')
                ->where('campaign_id', $campaignId)
                ->whereIn('status', [self::INSTANCE_UNUSED, self::INSTANCE_LOCKED])
                ->update(['status' => self::INSTANCE_VOIDED]);
        });

        $this->writeAudit($staffId, 'coupon.disable', $campaignId, [
            'previous_status' => (string) $row['status'],
        ]);

        return $this->shapeAdminCampaign($this->loadCampaignRow($campaignId));
    }

    /** 查询单个活动详情。 @return array<string, mixed> */
    public function showCampaign(int $campaignId): array
    {
        $row = $this->loadCampaignRow($campaignId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'COUPON_NOT_FOUND');
        }
        return $this->shapeAdminCampaign($row);
    }

    /**
     * 管理端分页列表；status 支持 active / scheduled / ended / disabled。
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int, page: int, limit: int}
     */
    public function listCampaignsForAdmin(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(self::MAX_PAGE_LIMIT, max(1, (int) ($filters['limit'] ?? 20)));
        $query = Db::name('coupon_campaigns');
        if (!empty($filters['scope_type'])) {
            $query->where('scope_type', (string) $filters['scope_type']);
        }
        if (!empty($filters['status'])) {
            $status = (string) $filters['status'];
            if ($status === self::STATUS_DISABLED) {
                $query->where('status', self::STATUS_DISABLED);
            } elseif (in_array($status, ['active', 'scheduled', 'ended'], true)) {
                $now = $this->nowDatetime();
                if ($status === 'scheduled') {
                    $query->where('status', self::STATUS_ACTIVE)
                        ->where('claim_starts_at', '>', $now);
                } elseif ($status === 'active') {
                    $query->where('status', self::STATUS_ACTIVE)
                        ->where('claim_starts_at', '<=', $now)
                        ->where('claim_ends_at', '>', $now);
                } else {
                    $query->where('claim_ends_at', '<=', $now);
                }
            }
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('created_at', 'desc')->page($page, $limit)->select()->toArray();
        $items = array_map(fn (array $r): array => $this->shapeAdminCampaign($r), $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    // -------------------------------------------------------------------------
    // 定向发放（管理端）
    // -------------------------------------------------------------------------

    /**
     * 批量向指定学员发放券实例；跳过已达 per_learner_claim_limit 的学员。
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function grantToLearners(int $campaignId, array $input, int $staffId): array
    {
        $this->assertActor($staffId);
        $learnerIds = $this->validateLearnerIds($input['learner_ids'] ?? null);

        $granted = 0;
        $skipped = 0;
        $inserted = [];

        Db::transaction(function () use (
            $campaignId,
            $learnerIds,
            $staffId,
            &$granted,
            &$skipped,
            &$inserted,
        ) {
            $row = $this->loadCampaignRowForUpdate($campaignId);
            if ($row === null) {
                throw new BusinessException('NOT_FOUND', 'COUPON_NOT_FOUND');
            }
            if ((string) $row['status'] !== self::STATUS_ACTIVE) {
                throw new BusinessException('CONFLICT', 'COUPON_NOT_GRANTABLE');
            }
            $expiresAt = $this->resolveExpiresAt($row);
            $perLearnerLimit = (int) $row['per_learner_claim_limit'];
            $totalQuota = $row['total_quota'] !== null ? (int) $row['total_quota'] : null;
            $currentClaimed = (int) $row['claimed_count'];

            foreach ($learnerIds as $learnerId) {
                $existing = (int) Db::name('learner_coupons')
                    ->where('campaign_id', (int) $row['id'])
                    ->where('learner_id', $learnerId)
                    ->count();
                if ($existing >= $perLearnerLimit) {
                    $skipped++;
                    continue;
                }
                if ($totalQuota !== null && $currentClaimed >= $totalQuota) {
                    throw new BusinessException('VALIDATION_FAILED', 'COUPON_QUOTA_EXCEEDED');
                }
                $now = $this->nowDatetime();
                $newId = (int) Db::name('learner_coupons')->insertGetId([
                    'campaign_id' => (int) $row['id'],
                    'learner_id' => $learnerId,
                    'status' => self::INSTANCE_UNUSED,
                    'source' => self::SOURCE_GRANT,
                    'granted_by' => $staffId,
                    'locked_order_id' => null,
                    'used_order_id' => null,
                    'expires_at' => $expiresAt,
                    'locked_at' => null,
                    'used_at' => null,
                    'created_at' => $now,
                ]);
                Db::name('coupon_campaigns')
                    ->where('id', (int) $row['id'])
                    ->update([
                        'claimed_count' => Db::raw('claimed_count + 1'),
                        'updated_at' => $now,
                    ]);
                $currentClaimed++;
                $granted++;
                $inserted[] = $newId;
            }
        });

        if ($granted > 0) {
            $this->writeAudit($staffId, 'coupon.grant', $campaignId, [
                'granted' => $granted,
                'skipped' => $skipped,
            ]);
        }

        $items = [];
        if ($inserted !== []) {
            $rows = Db::name('learner_coupons')
                ->whereIn('id', $inserted)
                ->select()->toArray();
            foreach ($rows as $r) {
                $items[] = $this->shapeLearnerCoupon($r, $this->loadCampaignRow((int) $r['campaign_id']));
            }
        }

        return ['granted' => $granted, 'skipped' => $skipped, 'items' => $items];
    }

    // -------------------------------------------------------------------------
    // 学员领取中心与我的优惠券
    // -------------------------------------------------------------------------

    /** 当前可公开领取的活动列表。 @return list<array<string, mixed>> */
    public function listClaimable(int $learnerId): array
    {
        $this->assertLearner($learnerId);
        $now = $this->nowDatetime();
        $rows = Db::name('coupon_campaigns')
            ->where('status', self::STATUS_ACTIVE)
            ->where('claim_mode', self::CLAIM_PUBLIC)
            ->where('claim_starts_at', '<=', $now)
            ->where('claim_ends_at', '>', $now)
            ->order('claim_ends_at', 'asc')
            ->select()->toArray();

        $out = [];
        foreach ($rows as $row) {
            $claimed = (int) $row['claimed_count'];
            $quota = $row['total_quota'] !== null ? (int) $row['total_quota'] : null;
            $owned = (int) Db::name('learner_coupons')
                ->where('campaign_id', (int) $row['id'])
                ->where('learner_id', $learnerId)
                ->count();
            if ($owned >= (int) $row['per_learner_claim_limit']) {
                continue;
            }
            if ($quota !== null && $claimed >= $quota) {
                continue;
            }
            $out[] = $this->shapePublicCampaign($row, $quota === null ? null : max(0, $quota - $claimed));
        }
        return $out;
    }

    /**
     * 学员一键领取；事务内 FOR UPDATE 校验配额与领取上限。
     *
     * @return array<string, mixed>
     */
    public function claimByLearner(int $campaignId, int $learnerId): array
    {
        $this->assertLearner($learnerId);
        $now = $this->nowDatetime();
        $newId = Db::transaction(function () use ($campaignId, $learnerId, $now) {
            $row = Db::name('coupon_campaigns')
                ->where('id', $campaignId)
                ->lock(true)
                ->find();
            if (!$row) {
                throw new BusinessException('NOT_FOUND', 'COUPON_NOT_FOUND');
            }
            if ((string) $row['status'] !== self::STATUS_ACTIVE) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_CLAIMABLE');
            }
            if ((string) $row['claim_mode'] !== self::CLAIM_PUBLIC) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_CLAIMABLE');
            }
            $startsAt = $this->sqlDatetimeTimestamp((string) $row['claim_starts_at']);
            $endsAt = $this->sqlDatetimeTimestamp((string) $row['claim_ends_at']);
            $nowTs = $this->nowTimestamp();
            if ($nowTs < $startsAt) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_CLAIMABLE');
            }
            if ($nowTs >= $endsAt) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_EXPIRED');
            }
            $owned = (int) Db::name('learner_coupons')
                ->where('campaign_id', $campaignId)
                ->where('learner_id', $learnerId)
                ->count();
            if ($owned >= (int) $row['per_learner_claim_limit']) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_CLAIM_LIMIT_EXCEEDED');
            }
            $quota = $row['total_quota'] !== null ? (int) $row['total_quota'] : null;
            if ($quota !== null && (int) $row['claimed_count'] >= $quota) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_QUOTA_EXCEEDED');
            }
            $expiresAt = $this->resolveExpiresAt($row);
            $newId = (int) Db::name('learner_coupons')->insertGetId([
                'campaign_id' => $campaignId,
                'learner_id' => $learnerId,
                'status' => self::INSTANCE_UNUSED,
                'source' => self::SOURCE_CLAIM,
                'granted_by' => null,
                'locked_order_id' => null,
                'used_order_id' => null,
                'expires_at' => $expiresAt,
                'locked_at' => null,
                'used_at' => null,
                'created_at' => $now,
            ]);
            Db::name('coupon_campaigns')
                ->where('id', $campaignId)
                ->update([
                    'claimed_count' => Db::raw('claimed_count + 1'),
                    'updated_at' => $now,
                ]);
            return $newId;
        });

        $row = Db::name('learner_coupons')->where('id', $newId)->find();
        $campaign = $this->loadCampaignRow((int) $row['campaign_id']);
        return $this->shapeLearnerCoupon($row, $campaign);
    }

    /**
     * 我的优惠券分页；按 expires_at 升序、id 降序。
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int, page: int, limit: int}
     */
    public function listMyCoupons(int $learnerId, array $filters): array
    {
        $this->assertLearner($learnerId);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(self::MAX_PAGE_LIMIT, max(1, (int) ($filters['limit'] ?? 20)));
        $query = Db::name('learner_coupons')->where('learner_id', $learnerId);
        $statusFilter = $filters['status'] ?? null;
        if (is_string($statusFilter) && $statusFilter !== '') {
            $query->where('status', $statusFilter);
        }
        $total = (int) (clone $query)->count();
        $rows = $query
            ->order('expires_at', 'asc')
            ->order('id', 'desc')
            ->page($page, $limit)
            ->select()->toArray();
        $items = [];
        foreach ($rows as $row) {
            $campaign = $this->loadCampaignRow((int) $row['campaign_id']);
            $items[] = $this->shapeLearnerCoupon($row, $campaign);
        }
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    // -------------------------------------------------------------------------
    // 结账预览与订单联动
    // -------------------------------------------------------------------------

    /**
     * 结账页可用券列表：计算课程现价、适用范围、门槛与 payable_preview。
     *
     * @return array<string, mixed>
     */
    public function listCheckoutOptions(int $learnerId, int $courseId): array
    {
        $this->assertLearner($learnerId);
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course || (string) $course['status'] !== 'published') {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $listPrice = (float) ($course['list_price'] ?? 0);
        $saleOpen = $this->isCourseSaleOpen($course);
        $basePrice = $saleOpen ? (float) $course['sale_price'] : $listPrice;

        $now = $this->nowDatetime();
        $rows = Db::name('learner_coupons')
            ->alias('lc')
            ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
            ->where('lc.learner_id', $learnerId)
            ->where('lc.status', self::INSTANCE_UNUSED)
            ->where('lc.expires_at', '>', $now)
            ->where('c.status', self::STATUS_ACTIVE)
            ->field([
                'lc.id as id',
                'lc.campaign_id',
                'c.name',
                'c.scope_type',
                'c.min_amount',
                'c.discount_amount',
                'c.per_learner_use_limit',
            ])
            ->select()->toArray();

        $usedCountByCampaign = $this->countUsedByLearner($learnerId);

        $items = [];
        foreach ($rows as $row) {
            if (!$this->campaignMatchesCourse($row, $course)) {
                continue;
            }
            $minAmount = (float) $row['min_amount'];
            $discount = (float) $row['discount_amount'];
            $eligible = $basePrice >= $minAmount;
            $usedCount = $usedCountByCampaign[(int) $row['campaign_id']] ?? 0;
            if ($usedCount >= (int) $row['per_learner_use_limit']) {
                $eligible = false;
            }
            $preview = $eligible ? max(0.0, $basePrice - $discount) : $basePrice;
            $reason = $eligible
                ? null
                : ($basePrice < $minAmount ? 'COUPON_MIN_AMOUNT_NOT_MET' : 'COUPON_USE_LIMIT_EXCEEDED');
            $items[] = [
                'id' => (int) $row['id'],
                'name' => (string) $row['name'],
                'min_amount' => $minAmount,
                'discount_amount' => $discount,
                'eligible' => $eligible,
                'ineligible_reason' => $reason,
                'payable_preview' => $preview,
            ];
        }
        return [
            'base_price' => $basePrice,
            'list_price' => $listPrice,
            'sale_price' => $saleOpen ? (float) $course['sale_price'] : 0.0,
            'items' => $items,
        ];
    }

    /**
     * 为待支付订单锁定券实例；无券时返回零折扣。
     *
     * 由 OrderService 在同一事务内调用；返回折扣快照供订单行写入。
     *
     * @return array{coupon_discount: float, campaign_id: int}
     */
    public function lockForOrder(int $learnerId, int $courseId, ?int $couponId, int $orderId): array
    {
        if ($couponId === null || $couponId <= 0) {
            return ['coupon_discount' => 0.0, 'campaign_id' => 0];
        }
        $this->assertLearner($learnerId);
        $now = $this->nowDatetime();
        $result = Db::transaction(function () use ($learnerId, $courseId, $couponId, $orderId, $now) {
            $coupon = Db::name('learner_coupons')
                ->alias('lc')
                ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
                ->where('lc.id', $couponId)
                ->field([
                    'lc.id', 'lc.campaign_id', 'lc.learner_id', 'lc.status as instance_status',
                    'lc.source', 'lc.granted_by', 'lc.locked_order_id', 'lc.used_order_id',
                    'lc.expires_at', 'lc.locked_at', 'lc.used_at', 'lc.created_at',
                    'c.status as campaign_status', 'c.scope_type', 'c.min_amount',
                    'c.discount_amount', 'c.per_learner_use_limit',
                ])
                ->lock(true)
                ->find();
            if (!$coupon) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_FOUND');
            }
            if ((int) $coupon['learner_id'] !== $learnerId) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_FOUND');
            }
            if ((string) $coupon['instance_status'] === self::INSTANCE_USED) {
                throw new BusinessException('CONFLICT', 'COUPON_ALREADY_USED');
            }
            if ((string) $coupon['instance_status'] === self::INSTANCE_LOCKED) {
                if ((int) ($coupon['locked_order_id'] ?? 0) !== $orderId) {
                    throw new BusinessException('CONFLICT', 'COUPON_LOCKED');
                }
                return [
                    'coupon_discount' => (float) $coupon['discount_amount'],
                    'campaign_id' => (int) $coupon['campaign_id'],
                ];
            }
            if ((string) $coupon['instance_status'] !== self::INSTANCE_UNUSED) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_CLAIMABLE');
            }
            if ((string) $coupon['instance_status'] === self::INSTANCE_UNUSED
                && $this->sqlDatetimeTimestamp((string) $coupon['expires_at']) <= $this->nowTimestamp()) {
                Db::name('learner_coupons')
                    ->where('id', $couponId)
                    ->update(['status' => self::INSTANCE_EXPIRED]);
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_EXPIRED');
            }
            if ((string) $coupon['campaign_status'] === self::STATUS_DISABLED) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_VOIDED');
            }
            $course = Db::name('courses')->where('id', $courseId)->find();
            if (!$course || (string) $course['status'] !== 'published') {
                throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
            }
            $saleOpen = $this->isCourseSaleOpen($course);
            $basePrice = $saleOpen ? (float) $course['sale_price'] : (float) $course['list_price'];
            if ((float) $coupon['min_amount'] > $basePrice) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_MIN_AMOUNT_NOT_MET');
            }
            if (!$this->campaignMatchesCourse($coupon, $course)) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_NOT_APPLICABLE');
            }
            $usedCount = (int) Db::name('learner_coupons')
                ->where('campaign_id', (int) $coupon['campaign_id'])
                ->where('learner_id', $learnerId)
                ->where('status', self::INSTANCE_USED)
                ->count();
            if ($usedCount >= (int) $coupon['per_learner_use_limit']) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_USE_LIMIT_EXCEEDED');
            }
            $discount = min((float) $coupon['discount_amount'], $basePrice);
            Db::name('learner_coupons')
                ->where('id', $couponId)
                ->update([
                    'status' => self::INSTANCE_LOCKED,
                    'locked_order_id' => $orderId,
                    'locked_at' => $now,
                ]);
            return [
                'coupon_discount' => $discount,
                'campaign_id' => (int) $coupon['campaign_id'],
            ];
        });
        return $result;
    }

    /**
     * 订单支付成功时核销券；与 OrderService::markSucceeded 同事务。
     * 支付回调重投时已核销实例幂等跳过。
     */
    public function redeemOnSuccess(int $orderId): void
    {
        $row = Db::name('learner_coupons')
            ->where('locked_order_id', $orderId)
            ->lock(true)
            ->find();
        if (!$row) {
            return;
        }
        if ((string) $row['status'] === self::INSTANCE_USED
            && (int) $row['used_order_id'] === $orderId) {
            return;
        }
        if ((string) $row['status'] !== self::INSTANCE_LOCKED) {
            return;
        }
        $now = $this->nowDatetime();
        Db::name('learner_coupons')
            ->where('id', (int) $row['id'])
            ->update([
                'status' => self::INSTANCE_USED,
                'used_order_id' => $orderId,
                'used_at' => $now,
            ]);
        Db::name('coupon_campaigns')
            ->where('id', (int) $row['campaign_id'])
            ->update(['used_count' => Db::raw('used_count + 1')]);
    }

    /**
     * 订单终态（失败/取消/超时）时释放锁定；未过期则恢复 unused，否则标记 expired。
     */
    public function releaseOnTerminal(int $orderId): void
    {
        $row = Db::name('learner_coupons')
            ->where('locked_order_id', $orderId)
            ->lock(true)
            ->find();
        if (!$row || (string) $row['status'] !== self::INSTANCE_LOCKED) {
            return;
        }
        $expiresAt = $this->sqlDatetimeTimestamp((string) $row['expires_at']);
        $nowTs = $this->nowTimestamp();
        $newStatus = $expiresAt > $nowTs ? self::INSTANCE_UNUSED : self::INSTANCE_EXPIRED;
        Db::name('learner_coupons')
            ->where('id', (int) $row['id'])
            ->update([
                'status' => $newStatus,
                'locked_order_id' => null,
                'locked_at' => null,
            ]);
    }

    // -------------------------------------------------------------------------
    // 核销记录（管理端）
    // -------------------------------------------------------------------------

    /**
     * 已核销券分页列表，含学员脱敏手机号与订单快照。
     *
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string,mixed>>, total: int, page: int, limit: int}
     */
    public function listRedemptions(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = min(self::MAX_PAGE_LIMIT, max(1, (int) ($filters['limit'] ?? 20)));
        $query = Db::name('learner_coupons')
            ->alias('lc')
            ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
            ->join('orders o', 'o.id = lc.used_order_id')
            ->join('courses co', 'co.id = o.course_id')
            ->join('accounts a', 'a.id = lc.learner_id')
            ->where('lc.status', self::INSTANCE_USED)
            ->whereNotNull('lc.used_order_id')
            ->field([
                'lc.id as redemption_id',
                'c.id as campaign_id',
                'c.name as campaign_name',
                'a.id as learner_id',
                'a.phone as learner_phone',
                'co.id as course_id',
                'co.title as course_title',
                'o.id as order_id',
                'o.coupon_discount_snapshot as discount_amount',
                'lc.used_at as used_at',
            ]);
        if (!empty($filters['campaign_id'])) {
            $query->where('c.id', (int) $filters['campaign_id']);
        }
        if (!empty($filters['learner_id'])) {
            $query->where('a.id', (int) $filters['learner_id']);
        }
        if (!empty($filters['from'])) {
            $query->where('lc.used_at', '>=', (string) $filters['from']);
        }
        if (!empty($filters['to'])) {
            $query->where('lc.used_at', '<=', (string) $filters['to']);
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('lc.used_at', 'desc')->page($page, $limit)->select()->toArray();
        $items = array_map(fn (array $r): array => [
            'redemption_id' => (int) $r['redemption_id'],
            'campaign_id' => (int) $r['campaign_id'],
            'campaign_name' => (string) $r['campaign_name'],
            'learner_id' => (int) $r['learner_id'],
            'learner_masked_phone' => $this->maskPhone((string) ($r['learner_phone'] ?? '')),
            'course_id' => (int) $r['course_id'],
            'course_title' => (string) $r['course_title'],
            'order_id' => (int) $r['order_id'],
            'discount_amount' => (float) $r['discount_amount'],
            'used_at' => $this->toIso8601((string) $r['used_at']),
        ], $rows);
        return ['items' => $items, 'total' => $total, 'page' => $page, 'limit' => $limit];
    }

    // -------------------------------------------------------------------------
    // 内部辅助
    // -------------------------------------------------------------------------

    /** 读取活动行（无锁）。 @return array<string, mixed>|null */
    private function loadCampaignRow(int $campaignId): ?array
    {
        $row = Db::name('coupon_campaigns')->where('id', $campaignId)->find();
        return is_array($row) ? $row : null;
    }

    /** 读取活动行并加行锁（FOR UPDATE）。 @return array<string, mixed>|null */
    private function loadCampaignRowForUpdate(int $campaignId): ?array
    {
        $row = Db::name('coupon_campaigns')->where('id', $campaignId)->lock(true)->find();
        return is_array($row) ? $row : null;
    }

    /** 写入适用范围关联表（分类或课程）。 @param array<string, mixed> $payload */
    private function insertScopeJunctions(int $campaignId, array $payload): void
    {
        if ($payload['scope_type'] === self::SCOPE_CATEGORY) {
            foreach ($payload['scope_category_ids'] as $cid) {
                Db::name('coupon_campaign_categories')->insert([
                    'campaign_id' => $campaignId,
                    'category_id' => (int) $cid,
                ]);
            }
            return;
        }
        if ($payload['scope_type'] === self::SCOPE_COURSE) {
            foreach ($payload['scope_course_ids'] as $cid) {
                Db::name('coupon_campaign_courses')->insert([
                    'campaign_id' => $campaignId,
                    'course_id' => (int) $cid,
                ]);
            }
        }
    }

    /**
     * 判断活动适用范围是否包含目标课程。
     * category 范围含子树（通过 categories.path 匹配）。
     *
     * @param array<string, mixed> $coupon  learner_coupons 与 coupon_campaigns 联结行
     */
    private function campaignMatchesCourse(array $coupon, array $course): bool
    {
        $scope = (string) $coupon['scope_type'];
        if ($scope === self::SCOPE_ALL) {
            return true;
        }
        if ($scope === self::SCOPE_COURSE) {
            $allowed = Db::name('coupon_campaign_courses')
                ->where('campaign_id', (int) $coupon['campaign_id'])
                ->column('course_id');
            return in_array((int) $course['id'], array_map('intval', $allowed), true);
        }
        // category：通过 categories.path 匹配子树
        $allowed = Db::name('coupon_campaign_categories')
            ->where('campaign_id', (int) $coupon['campaign_id'])
            ->column('category_id');
        if ($allowed === []) {
            return false;
        }
        $courseCategoryId = (int) ($course['category_id'] ?? 0);
        if (in_array($courseCategoryId, array_map('intval', $allowed), true)) {
            return true;
        }
        $categoryIds = array_map('intval', $allowed);
        foreach ($categoryIds as $cid) {
            $row = Db::name('categories')->where('id', $cid)->find();
            if (!$row) {
                continue;
            }
            $needle = '/' . $cid . '/';
            $path = (string) ($row['path'] ?? '');
            if ($path !== '' && (strpos($path, $needle) !== false || $path === '/' . $cid)) {
                return true;
            }
        }
        return false;
    }

    /** 按活动统计学员已使用张数。 @return array<int, int> */
    private function countUsedByLearner(int $learnerId): array
    {
        $rows = Db::name('learner_coupons')
            ->where('learner_id', $learnerId)
            ->where('status', self::INSTANCE_USED)
            ->field(['campaign_id', 'COUNT(*) as used'])
            ->group('campaign_id')
            ->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['campaign_id']] = (int) $r['used'];
        }
        return $out;
    }

    /** 校验并规范化创建/更新活动的输入。 @param array<string, mixed> $input */
    private function validateCampaignInput(array $input, bool $creating): array
    {
        $name = $this->validateName($input['name'] ?? null);
        $scope = $this->validateScopeType($input['scope_type'] ?? null);
        $minAmount = $this->validateMoney($input['min_amount'] ?? null, 'min_amount');
        $discount = $this->validateMoney($input['discount_amount'] ?? null, 'discount_amount');
        if ($discount <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        if ($minAmount > 0 && $discount > $minAmount) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        $claimMode = $this->validateClaimMode($input['claim_mode'] ?? null);
        $claimStartsAt = $this->validateIso8601ToSql($input['claim_starts_at'] ?? null, 'claim_starts_at');
        $claimEndsAt = $this->validateIso8601ToSql($input['claim_ends_at'] ?? null, 'claim_ends_at');
        if ($this->sqlDatetimeTimestamp($claimEndsAt) <= $this->sqlDatetimeTimestamp($claimStartsAt)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
        }
        $useEndsAtRaw = $input['use_ends_at'] ?? null;
        $useEndsAt = $useEndsAtRaw === null
            ? null
            : $this->validateIso8601ToSql($useEndsAtRaw, 'use_ends_at');
        if ($useEndsAt !== null && $this->sqlDatetimeTimestamp($useEndsAt) < $this->sqlDatetimeTimestamp($claimEndsAt)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
        }
        $totalQuota = $this->validateTotalQuota($input['total_quota'] ?? null);
        $perLearnerClaim = $this->validateLimit(
            $input['per_learner_claim_limit'] ?? self::DEFAULT_CLAIM_LIMIT,
            'per_learner_claim_limit',
        );
        $perLearnerUse = $this->validateLimit(
            $input['per_learner_use_limit'] ?? self::DEFAULT_USE_LIMIT,
            'per_learner_use_limit',
        );

        $categoryIds = $this->validateCategoryIds($input['scope_category_ids'] ?? []);
        $courseIds = $this->validateCourseIds($input['scope_course_ids'] ?? []);
        if ($scope === self::SCOPE_CATEGORY && $categoryIds === []) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
        }
        if ($scope === self::SCOPE_COURSE && $courseIds === []) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
        }
        if ($scope === self::SCOPE_ALL && ($categoryIds !== [] || $courseIds !== [])) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
        }

        return [
            'name' => $name,
            'scope_type' => $scope,
            'min_amount' => $minAmount,
            'discount_amount' => $discount,
            'claim_mode' => $claimMode,
            'claim_starts_at' => $claimStartsAt,
            'claim_ends_at' => $claimEndsAt,
            'use_ends_at' => $useEndsAt,
            'total_quota' => $totalQuota,
            'per_learner_claim_limit' => $perLearnerClaim,
            'per_learner_use_limit' => $perLearnerUse,
            'scope_category_ids' => $categoryIds,
            'scope_course_ids' => $courseIds,
        ];
    }

    /** 校验活动名称：非空且不超过 MAX_NAME_LENGTH。 */
    private function validateName(mixed $value): string
    {
        if (!is_string($value)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        $name = trim($value);
        if ($name === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $name;
    }

    /** 校验适用范围类型。 */
    private function validateScopeType(mixed $value): string
    {
        $scope = is_string($value) ? $value : '';
        if (!in_array($scope, [self::SCOPE_ALL, self::SCOPE_CATEGORY, self::SCOPE_COURSE], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $scope;
    }

    /** 校验领取方式。 */
    private function validateClaimMode(mixed $value): string
    {
        $mode = is_string($value) ? $value : '';
        if (!in_array($mode, [self::CLAIM_PUBLIC, self::CLAIM_ADMIN_ONLY], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $mode;
    }

    /** 校验金额字段：非负、两位小数、上限 1_000_000。 */
    private function validateMoney(mixed $value, string $field): float
    {
        if (is_string($value) && is_numeric($value)) {
            $value = (float) $value;
        }
        if (!is_int($value) && !is_float($value)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        $amount = round((float) $value, 2);
        if ($amount < 0 || $amount > 1_000_000) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $amount;
    }

    /** 校验总配额；null 表示不限量。 */
    private function validateTotalQuota(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 1_000_000) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $value;
    }

    /** 校验 per_learner 领取/使用上限。 */
    private function validateLimit(mixed $value, string $field): int
    {
        if (is_string($value) && ctype_digit($value)) {
            $value = (int) $value;
        }
        if (!is_int($value) || $value < 0 || $value > 1000) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $value;
    }

    /** 校验 ISO8601 时间并转为 Asia/Shanghai 的 SQL datetime。 */
    private function validateIso8601ToSql(mixed $value, string $field): string
    {
        if (!is_string($value)
            || preg_match(
                '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?(?:Z|[+-]\d{2}:\d{2})$/D',
                $value,
            ) !== 1) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
        }
        try {
            return (new \DateTimeImmutable($value))
                ->setTimezone(new \DateTimeZone(self::TIMEZONE))
                ->format('Y-m-d H:i:s');
        } catch (\Exception) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_DATE_INVALID');
        }
    }

    /** 乐观锁：校验 expected_updated_at。 */
    private function validateExpectedUpdatedAt(mixed $value): string
    {
        return $this->validateIso8601ToSql($value, 'expected_updated_at');
    }

    /** 校验分类 ID 列表。 @return list<int> */
    private function validateCategoryIds(mixed $value): array
    {
        if (!is_array($value)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
        }
        $out = [];
        foreach ($value as $id) {
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!is_int($id) || $id <= 0) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
            }
            $out[] = $id;
        }
        return $out;
    }

    /** 校验课程 ID 列表。 @return list<int> */
    private function validateCourseIds(mixed $value): array
    {
        if (!is_array($value)) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
        }
        $out = [];
        foreach ($value as $id) {
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!is_int($id) || $id <= 0) {
                throw new BusinessException('VALIDATION_FAILED', 'COUPON_SCOPE_REQUIRED');
            }
            $out[] = $id;
        }
        return $out;
    }

    /** 校验学员 ID 列表；去重且不超过 MAX_GRANT_BATCH。 @return list<int> */
    private function validateLearnerIds(mixed $value): array
    {
        if (!is_array($value) || $value === []) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        if (count($value) > self::MAX_GRANT_BATCH) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        $out = [];
        $seen = [];
        foreach ($value as $id) {
            if (is_string($id) && ctype_digit($id)) {
                $id = (int) $id;
            }
            if (!is_int($id) || $id <= 0 || isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $out[] = $id;
        }
        if ($out === []) {
            throw new BusinessException('VALIDATION_FAILED', 'COUPON_RULE_INVALID');
        }
        return $out;
    }

    /** 解析实例过期时间：优先 use_ends_at，否则取 claim_ends_at。 @param array<string, mixed> $row */
    private function resolveExpiresAt(array $row): string
    {
        return $row['use_ends_at'] !== null
            ? (string) $row['use_ends_at']
            : (string) $row['claim_ends_at'];
    }

    /** 生成严格递增的 updated_at，避免乐观锁同秒冲突。 */
    private function nextUpdatedAt(string $current): string
    {
        $tz = new \DateTimeZone(self::TIMEZONE);
        $now = new \DateTimeImmutable('now', $tz);
        $cur = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $current, $tz);
        $candidate = $now;
        if ($cur instanceof \DateTimeImmutable) {
            $candidate = max($candidate, $cur->modify('+1 second'));
        }
        return $candidate->format('Y-m-d H:i:s');
    }

    /** 校验后台操作人已登录。 */
    private function assertActor(int $staffId): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** 校验学员已登录。 */
    private function assertLearner(int $learnerId): void
    {
        if ($learnerId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** 写入 audit_log（H3：管理端写操作必经）。 @param array<string,mixed> $payload */
    private function writeAudit(int $staffId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => $action,
            'target_type' => 'coupon_campaigns',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $this->nowDatetime(),
        ]);
    }

    /** 当前时刻（Asia/Shanghai，SQL datetime）。 */
    private function nowDatetime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    /** Asia/Shanghai 当前 Unix 时间戳。 */
    private function nowTimestamp(): int
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    /** 将库内 SQL datetime（Asia/Shanghai 墙钟）解析为 Unix 时间戳。 */
    private function sqlDatetimeTimestamp(string $datetime): int
    {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TIMEZONE)))->getTimestamp();
    }

    /**
     * 课程是否处于限时优惠窗口内。
     *
     * @param array<string, mixed> $course
     */
    private function isCourseSaleOpen(array $course): bool
    {
        return (float) ($course['sale_price'] ?? 0) > 0
            && !empty($course['sale_start_at'])
            && !empty($course['sale_end_at'])
            && $this->nowTimestamp() >= $this->sqlDatetimeTimestamp((string) $course['sale_start_at'])
            && $this->nowTimestamp() < $this->sqlDatetimeTimestamp((string) $course['sale_end_at']);
    }

    /** SQL datetime → ISO8601（Asia/Shanghai）。 */
    private function toIso8601(string $datetime): string
    {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
    }

    /** 手机号脱敏：保留前 3 后 4 位。 */
    private function maskPhone(string $phone): string
    {
        $len = strlen($phone);
        if ($len < 7) {
            return str_repeat('*', $len);
        }
        return substr($phone, 0, 3) . str_repeat('*', $len - 7) . substr($phone, -4);
    }

    // -------------------------------------------------------------------------
    // 响应体整形
    // -------------------------------------------------------------------------

    /** 管理端活动 DTO。 @param array<string, mixed> $row */
    private function shapeAdminCampaign(array $row): array
    {
        $scopeType = (string) $row['scope_type'];
        $categoryIds = [];
        $courseIds = [];
        if ($scopeType === self::SCOPE_CATEGORY) {
            $categoryIds = array_map('intval', Db::name('coupon_campaign_categories')
                ->where('campaign_id', (int) $row['id'])
                ->column('category_id'));
        } elseif ($scopeType === self::SCOPE_COURSE) {
            $courseIds = array_map('intval', Db::name('coupon_campaign_courses')
                ->where('campaign_id', (int) $row['id'])
                ->column('course_id'));
        }
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'scope_type' => $scopeType,
            'scope_category_ids' => $categoryIds,
            'scope_course_ids' => $courseIds,
            'min_amount' => (float) $row['min_amount'],
            'discount_amount' => (float) $row['discount_amount'],
            'claim_mode' => (string) $row['claim_mode'],
            'claim_starts_at' => $this->toIso8601((string) $row['claim_starts_at']),
            'claim_ends_at' => $this->toIso8601((string) $row['claim_ends_at']),
            'use_ends_at' => $row['use_ends_at'] !== null
                ? $this->toIso8601((string) $row['use_ends_at'])
                : null,
            'total_quota' => $row['total_quota'] !== null ? (int) $row['total_quota'] : null,
            'claimed_count' => (int) $row['claimed_count'],
            'used_count' => (int) $row['used_count'],
            'per_learner_claim_limit' => (int) $row['per_learner_claim_limit'],
            'per_learner_use_limit' => (int) $row['per_learner_use_limit'],
            'status' => (string) $row['status'],
            'created_by' => (int) $row['created_by'],
            'created_at' => $this->toIso8601((string) $row['created_at']),
            'updated_at' => $this->toIso8601((string) $row['updated_at']),
        ];
    }

    /** 学习端可领取活动 DTO。 @param array<string, mixed> $row */
    private function shapePublicCampaign(array $row, ?int $remaining): array
    {
        $scopeSummary = $this->scopeSummary($row);
        return [
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'scope_type' => (string) $row['scope_type'],
            'scope_summary' => $scopeSummary,
            'min_amount' => (float) $row['min_amount'],
            'discount_amount' => (float) $row['discount_amount'],
            'claim_starts_at' => $this->toIso8601((string) $row['claim_starts_at']),
            'claim_ends_at' => $this->toIso8601((string) $row['claim_ends_at']),
            'use_ends_at' => $this->toIso8601(
                $row['use_ends_at'] !== null
                    ? (string) $row['use_ends_at']
                    : (string) $row['claim_ends_at'],
            ),
            'remaining_quota' => $remaining,
        ];
    }

    /** 学员券实例 DTO。 @param array<string, mixed> $row */
    private function shapeLearnerCoupon(array $row, ?array $campaign): array
    {
        $campaign = $campaign ?? $this->loadCampaignRow((int) $row['campaign_id']);
        $scopeSummary = $campaign === null ? '' : $this->scopeSummary($campaign);
        return [
            'id' => (int) $row['id'],
            'campaign_id' => (int) $row['campaign_id'],
            'name' => $campaign['name'] ?? '',
            'scope_type' => $campaign['scope_type'] ?? self::SCOPE_ALL,
            'scope_summary' => $scopeSummary,
            'min_amount' => $campaign !== null ? (float) $campaign['min_amount'] : 0.0,
            'discount_amount' => $campaign !== null ? (float) $campaign['discount_amount'] : 0.0,
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'expires_at' => $this->toIso8601((string) $row['expires_at']),
            'created_at' => $this->toIso8601((string) $row['created_at']),
        ];
    }

    /** 适用范围摘要文案（全站 / 指定分类 / 指定课程）。 @param array<string, mixed> $row */
    private function scopeSummary(array $row): string
    {
        $scope = (string) $row['scope_type'];
        if ($scope === self::SCOPE_ALL) {
            return '无门槛';
        }
        if ($scope === self::SCOPE_CATEGORY) {
            $names = Db::name('coupon_campaign_categories')
                ->alias('cc')
                ->join('categories c', 'c.id = cc.category_id')
                ->where('cc.campaign_id', (int) $row['id'])
                ->column('c.name');
            return '指定分类:' . implode('、', array_map('strval', $names ?: ['未命名']));
        }
        if ($scope === self::SCOPE_COURSE) {
            $names = Db::name('coupon_campaign_courses')
                ->alias('cc')
                ->join('courses c', 'c.id = cc.course_id')
                ->where('cc.campaign_id', (int) $row['id'])
                ->column('c.title');
            return '指定课程:' . implode('、', array_map('strval', $names ?: ['未命名']));
        }
        return '';
    }
}
