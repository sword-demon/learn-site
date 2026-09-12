<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

use function maskPhone;
use function nowDatetime;
use function toIso8601;

final class CommissionService
{
    // 佣金状态机: pending → settled (退款窗口结束且未退款) / voided (退款或管理员撤销);
    // pending_blocked 是推荐人账户不可用时的冻结态, 不升级、不转赠、不转 settled.
    private const STATUS_PENDING = 'pending';
    private const STATUS_SETTLED = 'settled';
    private const STATUS_VOIDED = 'voided';
    private const STATUS_BLOCKED = 'pending_blocked';

    // 单笔订单最多向 LEVEL_CAP_HARD_LIMIT 名推荐人发放佣金, 与 level_cap 共享同一硬上限.
    private const MAX_RECEIVERS = DistributionConfigService::LEVEL_CAP_HARD_LIMIT;

    public function __construct(
        private readonly DistributionConfigService $config = new DistributionConfigService(),
        private readonly ReferralBindingService $referrals = new ReferralBindingService(),
        private readonly DistributionAuditService $audit = new DistributionAuditService(),
    ) {}

    public function settleForOrder(int $orderId): void
    {
        Db::transaction(function () use ($orderId): void {
            $order = Db::name('orders')->where('id', $orderId)->lock(true)->find();
            if (!$order || (string) $order['status'] !== 'succeeded') {
                return;
            }
            $existing = (int) Db::name('commission_records')->where('order_id', $orderId)->count();
            if ($existing > 0) {
                return;
            }

            $courseId = (int) $order['course_id'];
            $override = Db::name('distribution_course_overrides')->where('course_id', $courseId)->find();
            $cfg = $this->config->getConfig();
            if (!$this->shouldSettle($cfg, is_array($override) ? $override : null)) {
                return;
            }

            $effective = $this->effectiveConfig($cfg, is_array($override) ? $override : null);
            $paidCents = (int) round(((float) $order['paid_amount']) * 100);
            $chain = $this->referrals->walkUp((int) $order['learner_id'], (int) $effective['level_cap']);
            if ($chain === []) {
                return;
            }
            if (count($chain) > self::MAX_RECEIVERS) {
                $this->audit->record('system', null, 'commission.settle', 'commission', $orderId, null, ['rejected' => 'RECEIVER_COUNT'], '数据完整性硬约束');
                throw new BusinessException('VALIDATION_FAILED', 'RECEIVER_COUNT_EXCEEDS_HARD_LIMIT');
            }

            $amounts = $this->allocate($orderId, $paidCents, $effective, count($chain));
            $now = nowDatetime();
            $snapshot = json_encode($effective, JSON_UNESCAPED_UNICODE);
            $receivers = [];
            foreach ($chain as $index => $referrerId) {
                if (isset($receivers[$referrerId])) {
                    $this->audit->record('system', null, 'commission.settle', 'commission', $orderId, null, ['rejected' => 'DUPLICATE'], '数据完整性硬约束');
                    throw new BusinessException('VALIDATION_FAILED', 'DUPLICATE_RECEIVER');
                }
                $receivers[$referrerId] = true;
                $status = $this->referrerStatus($referrerId);
                $level = $index + 1;
                $amount = $this->applyLearnerCaps($referrerId, $courseId, $amounts[$level] ?? 0, $effective);
                try {
                    Db::name('commission_records')->insert([
                        'order_id' => $orderId,
                        'course_id' => $courseId,
                        'referee_learner_id' => (int) $order['learner_id'],
                        'referrer_learner_id' => $referrerId,
                        'level' => $level,
                        'amount_cents' => $amount,
                        'status' => $status,
                        'source' => 'system_settle',
                        'config_snapshot_json' => $snapshot,
                        'order_paid_cents_snapshot' => $paidCents,
                        'created_at' => $now,
                    ]);
                } catch (\Throwable $e) {
                    $this->audit->record('system', null, 'commission.settle', 'commission', $orderId, null, ['err' => $e->getMessage()], 'UNIQUE collision');
                    throw $e;
                }
            }
            $this->audit->record('system', null, 'commission.settle', 'commission', $orderId, null, [
                'receivers' => array_keys($receivers),
                'paid_cents' => $paidCents,
            ], null);
        });
    }

    public function markSettledForOrder(int $orderId): void
    {
        Db::transaction(function () use ($orderId): void {
            $order = Db::name('orders')->where('id', $orderId)->lock(true)->find();
            if (!$order) {
                return;
            }
            if ((string) $order['status'] === 'refunded') {
                return;
            }
            $now = nowDatetime();
            Db::name('commission_records')
                ->where('order_id', $orderId)
                ->where('status', self::STATUS_PENDING)
                ->update(['status' => self::STATUS_SETTLED, 'settled_at' => $now]);
        });
    }

    public function voidForOrder(int $orderId, string $reason): void
    {
        Db::transaction(function () use ($orderId, $reason): void {
            $order = Db::name('orders')->where('id', $orderId)->lock(true)->find();
            if (!$order) {
                return;
            }
            $now = nowDatetime();
            $before = Db::name('commission_records')->where('order_id', $orderId)->select()->toArray();
            Db::name('commission_records')
                ->where('order_id', $orderId)
                ->where('status', '<>', self::STATUS_VOIDED)
                ->update([
                    'status' => self::STATUS_VOIDED,
                    'source' => 'system_refund_void',
                    'voided_at' => $now,
                    'void_reason' => $reason === 'order_refund' ? '关联订单退款' : $reason,
                ]);
            $this->audit->record('system', null, 'commission.void_refund', 'commission', $orderId, $before, ['reason' => $reason], '关联订单退款');
        });
    }

    /** @return array<string, mixed> */
    public function voidByAdmin(int $commissionId, int $actorId, string $reason): array
    {
        $reason = trim($reason);
        if (mb_strlen($reason) < DistributionConfigService::COMMISSION_VOID_MIN_REASON_LEN) {
            throw new BusinessException('VALIDATION_FAILED', 'VOID_REASON_TOO_SHORT');
        }
        return Db::transaction(function () use ($commissionId, $actorId, $reason) {
            $row = Db::name('commission_records')->where('id', $commissionId)->lock(true)->find();
            if (!$row) {
                throw new BusinessException('NOT_FOUND', 'COMMISSION_NOT_FOUND');
            }
            if ((string) $row['status'] === self::STATUS_VOIDED) {
                throw new BusinessException('VALIDATION_FAILED', 'COMMISSION_ALREADY_VOIDED');
            }
            $count = (int) Db::name('commission_records')->where('order_id', (int) $row['order_id'])->count();
            if ($count > self::MAX_RECEIVERS) {
                throw new BusinessException('VALIDATION_FAILED', '数据完整性硬约束');
            }
            $now = nowDatetime();
            Db::name('commission_records')->where('id', $commissionId)->update([
                'status' => self::STATUS_VOIDED,
                'source' => 'admin_void',
                'voided_at' => $now,
                'void_reason' => $reason,
            ]);
            $after = Db::name('commission_records')->where('id', $commissionId)->find();
            $this->audit->record('admin', $actorId, 'commission.void_admin', 'commission', $commissionId, $row, is_array($after) ? $after : null, $reason);
            return $this->shapeRecords([$after ?? $row])[0];
        });
    }

    /** @return array<string, mixed> */
    public function listForLearner(int $learnerId, int $page, int $limit, ?string $status, bool $canViewDetail): array
    {
        if (!$canViewDetail) {
            return [
                'items' => [],
                'total' => 0,
                'page' => $page,
                'limit' => $limit,
                'summary' => ['pending_cents' => 0, 'settled_cents' => 0, 'voided_cents' => 0, 'total_cents' => 0],
            ];
        }
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $totalQ = Db::name('commission_records')->where('referrer_learner_id', $learnerId);
        $listQ = Db::name('commission_records')->where('referrer_learner_id', $learnerId);
        if ($status !== null && $status !== '') {
            $totalQ->where('status', $status);
            $listQ->where('status', $status);
        }
        $total = (int) $totalQ->count();
        $rows = $listQ->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $summaryRows = Db::name('commission_records')
            ->where('referrer_learner_id', $learnerId)
            ->field('status, SUM(amount_cents) AS cents')
            ->group('status')
            ->select()
            ->toArray();
        $summary = ['pending_cents' => 0, 'settled_cents' => 0, 'voided_cents' => 0, 'total_cents' => 0];
        foreach ($summaryRows as $s) {
            $cents = (int) $s['cents'];
            $st = (string) $s['status'];
            if ($st === self::STATUS_PENDING || $st === self::STATUS_BLOCKED) {
                $summary['pending_cents'] += $cents;
            } elseif ($st === self::STATUS_SETTLED) {
                $summary['settled_cents'] += $cents;
            } elseif ($st === self::STATUS_VOIDED) {
                $summary['voided_cents'] += $cents;
            }
            $summary['total_cents'] += $cents;
        }
        return [
            'items' => $this->shapeRecords($rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'summary' => $summary,
        ];
    }

    /** @return array<string, mixed> */
    public function downline(int $learnerId, ?int $level, bool $canViewDetail): array
    {
        if (!$canViewDetail) {
            return ['items' => []];
        }
        $items = [];
        $this->collectDownline($learnerId, 1, DistributionConfigService::LEVEL_CAP_HARD_LIMIT, $items);
        if ($level !== null) {
            $items = array_values(array_filter($items, fn(array $row): bool => $row['level'] === $level));
        }
        return ['items' => $items];
    }

    /** @return array<string, mixed> */
    public function getByOrder(int $orderId): array
    {
        $rows = Db::name('commission_records')->where('order_id', $orderId)->order('level', 'asc')->select()->toArray();
        if ($rows === []) {
            $order = Db::name('orders')->where('id', $orderId)->find();
            if (!$order) {
                throw new BusinessException('NOT_FOUND', 'ORDER_COMMISSION_NOT_FOUND');
            }
            return [
                'order_id' => $orderId,
                'course_id' => (int) $order['course_id'],
                'order_paid_cents_snapshot' => (int) round(((float) $order['paid_amount']) * 100),
                'config_snapshot' => $this->config->getConfig(),
                'receivers' => [],
            ];
        }
        $first = $rows[0];
        $cfg = json_decode((string) $first['config_snapshot_json'], true);
        $receivers = [];
        foreach ($rows as $row) {
            $phone = $this->learnerPhone((int) $row['referrer_learner_id']);
            $receivers[] = [
                'id' => (int) $row['id'],
                'referrer_learner_id' => (int) $row['referrer_learner_id'],
                'referrer_masked_phone' => maskPhone($phone),
                'level' => (int) $row['level'],
                'amount_cents' => (int) $row['amount_cents'],
                'status' => (string) $row['status'],
                'source' => (string) $row['source'],
                'settled_at' => $row['settled_at'] ? toIso8601(strtotime((string) $row['settled_at']) ?: null) : null,
                'voided_at' => $row['voided_at'] ? toIso8601(strtotime((string) $row['voided_at']) ?: null) : null,
                'void_reason' => $row['void_reason'] ?? null,
            ];
        }
        return [
            'order_id' => $orderId,
            'course_id' => (int) $first['course_id'],
            'order_paid_cents_snapshot' => (int) $first['order_paid_cents_snapshot'],
            'config_snapshot' => is_array($cfg) ? array_merge($this->config->getConfig(), $cfg) : $this->config->getConfig(),
            'receivers' => $receivers,
        ];
    }

    /**
     * @param array<string, mixed> $filter
     * @return array<string, mixed>
     */
    public function listForAdmin(array $filter, int $page, int $limit): array
    {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));
        $apply = function ($q) use ($filter) {
            if (!empty($filter['order_id'])) {
                $q->where('order_id', (int) $filter['order_id']);
            }
            if (!empty($filter['learner_id'])) {
                $q->where('referrer_learner_id', (int) $filter['learner_id']);
            }
            if (!empty($filter['course_id'])) {
                $q->where('course_id', (int) $filter['course_id']);
            }
            if (!empty($filter['status'])) {
                $q->where('status', (string) $filter['status']);
            }
            return $q;
        };
        $total = (int) $apply(Db::name('commission_records'))->count();
        $rows = $apply(Db::name('commission_records'))->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return [
            'items' => $this->shapeRecords($rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @param array<string, mixed> $filter */
    public function exportCsv(array $filter): string
    {
        $list = $this->listForAdmin($filter, 1, 200);
        $lines = ['id,order_id,course_id,referrer_masked_phone,level,amount_cents,status'];
        $ids = array_map(static fn (array $row): int => (int) $row['id'], $list['items']);
        $referrers = $ids === []
            ? []
            : Db::name('commission_records')->whereIn('id', $ids)->column('referrer_learner_id', 'id');
        foreach ($list['items'] as $row) {
            $referrerId = (int) ($referrers[(int) $row['id']] ?? 0);
            $lines[] = implode(',', [
                $row['id'],
                $row['order_id'],
                $row['course_id'],
                maskPhone($this->learnerPhone($referrerId)),
                $row['level'],
                $row['amount_cents'],
                $row['status'],
            ]);
        }
        return implode("\n", $lines) . "\n";
    }

    /** @param array<string, mixed> $cfg */
    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed>|null $override
     */
    private function shouldSettle(array $cfg, ?array $override): bool
    {
        if ($override !== null) {
            return (int) $override['enabled'] === 1;
        }
        return !empty($cfg['enabled']);
    }

    /**
     * @param array<string, mixed> $cfg
     * @param array<string, mixed>|null $override
     * @return array<string, mixed>
     */
    private function effectiveConfig(array $cfg, ?array $override): array
    {
        if ($override === null) {
            return $cfg;
        }
        foreach (['level1_pct', 'level2_pct', 'level3_pct', 'per_order_cap_cents', 'per_learner_course_cap_cents'] as $key) {
            if ($override[$key] !== null) {
                $cfg[$key] = $override[$key];
            }
        }
        return $cfg;
    }

    /**
     * @param array<string, mixed> $cfg
     * @return array<int, int>
     */
    private function allocate(int $orderId, int $paidCents, array $cfg, int $levels): array
    {
        $cap = (int) ($cfg['per_order_cap_cents'] ?? 0);
        $raw = [];
        $sum = 0;
        for ($level = 1; $level <= $levels; $level++) {
            $pct = (float) ($cfg['level' . $level . '_pct'] ?? 0);
            $raw[$level] = (int) floor($paidCents * $pct);
            $sum += $raw[$level];
        }
        if ($cap > 0 && $sum > $cap) {
            $overflow = $sum - $cap;
            for ($level = 3; $level >= 1 && $overflow > 0; $level--) {
                if (!isset($raw[$level])) {
                    continue;
                }
                $cut = min($raw[$level], $overflow);
                $raw[$level] -= $cut;
                $overflow -= $cut;
                $this->audit->record('system', null, 'commission.settle', 'commission', $orderId, null, [
                    'truncated_level' => $level,
                    'cut_cents' => $cut,
                ], '已截断');
            }
        }
        return $raw;
    }

    /**
     * @param array<string, mixed> $cfg
     */
    private function applyLearnerCaps(int $referrerId, int $courseId, int $amount, array $cfg): int
    {
        if ($amount <= 0) {
            return 0;
        }
        $active = ['pending', 'settled', 'pending_blocked'];
        $courseCap = (int) ($cfg['per_learner_course_cap_cents'] ?? 0);
        if ($courseCap > 0) {
            $usedCourse = (int) Db::name('commission_records')
                ->where('referrer_learner_id', $referrerId)
                ->where('course_id', $courseId)
                ->whereIn('status', $active)
                ->sum('amount_cents');
            $amount = min($amount, max(0, $courseCap - $usedCourse));
        }
        $totalCap = $cfg['per_learner_total_cap_cents'] ?? null;
        if ($totalCap !== null && $totalCap !== '') {
            $usedTotal = (int) Db::name('commission_records')
                ->where('referrer_learner_id', $referrerId)
                ->whereIn('status', $active)
                ->sum('amount_cents');
            $amount = min($amount, max(0, (int) $totalCap - $usedTotal));
        }
        return $amount;
    }

    private function referrerStatus(int $referrerId): string
    {
        $account = Db::name('accounts')->where('id', $referrerId)->find();
        if (!$account || (string) $account['status'] !== 'active') {
            return self::STATUS_BLOCKED;
        }
        return self::STATUS_PENDING;
    }

    private function learnerPhone(int $learnerId): string
    {
        $login = Db::name('accounts')->where('id', $learnerId)->value('login');
        return is_string($login) ? $login : '';
    }

    /**
     * Shape a batch of rows with two batched lookups instead of two queries
     * per row — list pages can carry up to 200 records.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    private function shapeRecords(array $rows): array
    {
        if ($rows === []) {
            return [];
        }
        $refereeIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['referee_learner_id'],
            $rows,
        )));
        $courseIds = array_values(array_unique(array_map(
            static fn (array $row): int => (int) $row['course_id'],
            $rows,
        )));
        $phones = Db::name('accounts')->whereIn('id', $refereeIds)->column('login', 'id');
        $titles = Db::name('courses')->whereIn('id', $courseIds)->column('title', 'id');
        $shaped = [];
        foreach ($rows as $row) {
            $shaped[] = $this->shapeRecord($row, is_array($phones) ? $phones : [], is_array($titles) ? $titles : []);
        }
        return $shaped;
    }

    /**
     * @param array<string, mixed> $row
     * @param array<int, string> $phones account_id → login
     * @param array<int, string> $titles course_id → title
     * @return array<string, mixed>
     */
    private function shapeRecord(array $row, array $phones, array $titles): array
    {
        $phone = (string) ($phones[(int) $row['referee_learner_id']] ?? '');
        $courseTitle = (string) ($titles[(int) $row['course_id']] ?? '');
        return [
            'id' => (int) $row['id'],
            'order_id' => (int) $row['order_id'],
            'course_id' => (int) $row['course_id'],
            'course_title' => $courseTitle !== '' ? $courseTitle : '课程',
            'referee_masked_phone' => maskPhone($phone),
            'level' => (int) $row['level'],
            'amount_cents' => (int) $row['amount_cents'],
            'status' => (string) $row['status'],
            'source' => (string) $row['source'],
            'created_at' => toIso8601(strtotime((string) $row['created_at']) ?: time()) ?? '',
            'settled_at' => $row['settled_at'] ? toIso8601(strtotime((string) $row['settled_at']) ?: null) : null,
            'voided_at' => $row['voided_at'] ? toIso8601(strtotime((string) $row['voided_at']) ?: null) : null,
            'void_reason' => $row['void_reason'] ?? null,
        ];
    }

    /** @param list<array<string, mixed>> $items */
    private function collectDownline(int $learnerId, int $level, int $max, array &$items): void
    {
        if ($level > $max) {
            return;
        }
        $children = Db::name('learners')->where('referrer_learner_id', $learnerId)->select()->toArray();
        foreach ($children as $child) {
            $id = (int) $child['account_id'];
            $items[] = [
                'learner_id' => $id,
                'masked_phone' => maskPhone($this->learnerPhone($id)),
                'level' => $level,
                'registered_at' => toIso8601(strtotime((string) $child['created_at']) ?: time()) ?? '',
            ];
            $this->collectDownline($id, $level + 1, $max, $items);
        }
    }
}
