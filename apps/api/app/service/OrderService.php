<?php
declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use App\support\payment\PaymentAdapter;
use App\support\payment\FakePaymentAdapter;
use App\service\DataScopeService;
use support\think\Db;

/**
 * OrderService — Phase 6 / US3. Owns the order book and the lifecycle
 * from pending → succeeded|failed|cancelled|unknown. Mediates between
 * the OrderController (creates pending orders + reads), the payment
 * adapter (issues the QR / scan parameters), and EntitlementService
 * (issues the purchase grant on succeeded).
 *
 * Invariants enforced here (and at the schema where possible):
 *   1. Order rows are immutable snapshots — list_price_snapshot,
 *      sale_price_snapshot, paid_amount are stamped at create time and
 *      never re-derived from the course.
 *   2. Only one pending order per (learner, course) — a second POST
 *      while the first is still pending returns the existing order
 *      with its original code_url, so the user can resume the same
 *      scan instead of paying twice.
 *   3. The status machine is: pending → {succeeded|failed|cancelled|unknown}.
 *      succeeded is the only state that grants an entitlement, and it
 *      can only be set by PaymentAdapter::onNotify() (the callback path
 *      that we route through markSucceeded()).
 *   4. succeeded_at is set on the same transaction that flips status
 *      to succeeded; nothing else ever writes it.
 */
final class OrderService
{
    public function __construct(
        private readonly EntitlementService $entitlements,
        private readonly PaymentAdapter $payment,
        private readonly ?CouponService $coupons = null,
    ) {
        if ($payment instanceof FakePaymentAdapter) {
            $payment->setSuccessHandler(function (int $orderId, string $providerRef): void {
                $this->markSucceeded($orderId, $providerRef);
            });
        }
    }

    /**
     * Create a pending order for a paid course. Idempotent per
     * (learner, course) when an existing pending order exists. Caller
     * is responsible for the 409-already-entitled short-circuit; this
     * method assumes the learner is NOT yet entitled.
     *
     * $learnerCouponId is optional; when provided, the matching
     * `learner_coupons` row is validated + locked in the same
     * transaction as the order insert, and the resulting discount is
     * stamped into the order's immutable snapshot.
     *
     * Returns the API envelope:
     *   { order_id, status, list_price_snapshot, sale_price_snapshot,
     *     coupon_discount_snapshot, paid_amount, learner_coupon_id,
     *     payment: {...} }
     */
    /** @return array<string, mixed> */
    public function createPending(int $learnerId, int $courseId, ?int $learnerCouponId = null): array
    {
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course || $course['status'] !== 'published') {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        if ((string) $course['price_mode'] !== 'paid') {
            throw new BusinessException('CONFLICT', 'COURSE_FREE');
        }
        if ((float) ($course['list_price'] ?? 0) <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_PRICE_INVALID');
        }

        // A pending order already represents a confirmed price. Reuse its
        // immutable snapshot even when the course's sale window has since
        // closed; only a brand-new confirmation must revalidate the window.
        // When a coupon was locked against the existing pending order, the
        // caller must echo back the same learner_coupon_id (research R8).
        $existingPending = Db::name('orders')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->where('status', 'pending')
            ->find();
        if ($existingPending) {
            $orderId = (int) $existingPending['id'];
            $existingCouponId = isset($existingPending['learner_coupon_id'])
                ? (int) $existingPending['learner_coupon_id']
                : 0;
            if ($learnerCouponId !== null && $learnerCouponId > 0
                && $learnerCouponId !== $existingCouponId) {
                throw new BusinessException('CONFLICT', 'ORDER_PENDING_COUPON_MISMATCH');
            }
            $amount = (float) $existingPending['paid_amount'];
            $paymentEnvelope = $this->payment->createCharge($orderId, $amount, 'CNY');
            if ($this->payment instanceof FakePaymentAdapter) {
                $this->payment->scheduleSuccess($orderId);
            }
            return $this->shapeCreateResponse($existingPending, $paymentEnvelope);
        }

        // Sale window check: if a sale price is declared it must be in
        // an open window to count as the snapshot.
        $now = time();
        $saleStart = $course['sale_start_at'] ? strtotime((string) $course['sale_start_at']) : null;
        $saleEnd   = $course['sale_end_at']   ? strtotime((string) $course['sale_end_at'])   : null;
        $saleOpen  = (float) ($course['sale_price'] ?? 0) > 0
            && $saleStart !== null && $saleEnd !== null
            && $now >= $saleStart && $now < $saleEnd;

        if ((float) ($course['sale_price'] ?? 0) > 0 && !$saleOpen) {
            // A published course may outlive its limited-time offer. Do not
            // silently charge the list price from a stale confirmation page;
            // make the client refresh the current price instead.
            throw new BusinessException('VALIDATION_FAILED', 'SALE_WINDOW_EXPIRED');
        }

        $listPrice = (float) $course['list_price'];
        $salePrice = $saleOpen ? (float) $course['sale_price'] : 0.0;
        $paidAmount = $saleOpen ? $salePrice : $listPrice;

        $nowDt = date('Y-m-d H:i:s');

        $orderId = (int) Db::transaction(function () use (
            $learnerId,
            $courseId,
            $listPrice,
            $salePrice,
            $paidAmount,
            $nowDt,
            $learnerCouponId,
        ) {
            // Idempotency: if a pending order for this (learner, course)
            // exists, return it as-is.
            $existing = Db::name('orders')
                ->where('learner_id', $learnerId)
                ->where('course_id', $courseId)
                ->where('status', 'pending')
                ->lock(true)
                ->find();
            if ($existing) {
                return (int) $existing['id'];
            }

            $newId = (int) Db::name('orders')->insertGetId([
                'learner_id'           => $learnerId,
                'course_id'            => $courseId,
                'learner_coupon_id'    => null,
                'list_price_snapshot'  => $listPrice,
                'sale_price_snapshot'  => $salePrice,
                'coupon_discount_snapshot' => 0,
                'paid_amount'          => $paidAmount,
                'currency'             => 'CNY',
                'status'               => 'pending',
                'provider'             => 'fake',
                'provider_ref'         => null,
                'succeeded_at'         => null,
                'created_at'           => $nowDt,
                'updated_at'           => $nowDt,
            ]);

            if ($learnerCouponId !== null && $learnerCouponId > 0 && $this->coupons !== null) {
                $lockResult = $this->coupons->lockForOrder(
                    $learnerId,
                    $courseId,
                    $learnerCouponId,
                    $newId,
                );
                $discount = (float) $lockResult['coupon_discount'];
                if ($discount > 0) {
                    $finalPaid = max(0.0, $paidAmount - $discount);
                    Db::name('orders')->where('id', $newId)->update([
                        'learner_coupon_id' => $learnerCouponId,
                        'coupon_discount_snapshot' => $discount,
                        'paid_amount' => $finalPaid,
                        'updated_at' => $nowDt,
                    ]);
                }
            }

            return $newId;
        });

        $orderRow = Db::name('orders')->where('id', $orderId)->find();

        Logger::info('order.created', [
            'order_id'   => $orderId,
            'learner_id' => $learnerId,
            'course_id'  => $courseId,
            'paid_amount'=> (float) $orderRow['paid_amount'],
            'coupon_id'  => isset($orderRow['learner_coupon_id']) ? (int) $orderRow['learner_coupon_id'] : null,
        ]);

        $effectiveAmount = (float) $orderRow['paid_amount'];
        $paymentEnvelope = $this->payment->createCharge($orderId, $effectiveAmount, 'CNY');
        if ($this->payment instanceof FakePaymentAdapter) {
            $this->payment->scheduleSuccess($orderId);
        }
        return $this->shapeCreateResponse($orderRow, $paymentEnvelope);
    }

    /**
     * @param array<string, mixed> $orderRow
     * @param array<string, mixed> $paymentEnvelope
     * @return array<string, mixed>
     */
    private function shapeCreateResponse(array $orderRow, array $paymentEnvelope): array
    {
        return [
            'order_id' => (int) $orderRow['id'],
            'status' => (string) $orderRow['status'],
            'list_price_snapshot' => (float) $orderRow['list_price_snapshot'],
            'sale_price_snapshot' => (float) $orderRow['sale_price_snapshot'],
            'coupon_discount_snapshot' => (float) ($orderRow['coupon_discount_snapshot'] ?? 0),
            'paid_amount' => (float) $orderRow['paid_amount'],
            'learner_coupon_id' => isset($orderRow['learner_coupon_id'])
                ? (int) $orderRow['learner_coupon_id']
                : null,
            'payment' => $paymentEnvelope,
        ];
    }

    /**
     * POST /courses/{id}/orders list endpoint. status=null returns every
     * status; status=pending/succeeded/... narrows. Sort is newest first.
     */
    /** @return list<array<string, mixed>> */
    public function listForLearner(int $learnerId, ?string $status, int $page, int $limit): array
    {
        $q = Db::name('orders')->where('learner_id', $learnerId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }
        $rows = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $out = [];
        foreach ($rows as $r) {
            $out[] = $this->shapeOrder($r);
        }
        return $out;
    }

    public function countForLearner(int $learnerId, ?string $status): int
    {
        $q = Db::name('orders')->where('learner_id', $learnerId);
        if ($status !== null && $status !== '') {
            $q->where('status', $status);
        }
        return (int) $q->count();
    }

    /**
     * Look up a single order, enforcing ownership. Returns null when
     * the order doesn't exist OR belongs to a different learner — we
     * don't distinguish the two at the API surface.
     */
    /** @return array<string, mixed>|null */
    public function findForLearner(int $learnerId, int $orderId): ?array
    {
        $row = Db::name('orders')
            ->alias('o')
            ->join('courses c', 'c.id = o.course_id')
            ->where('o.id', $orderId)
            ->field('o.*, c.title AS course_title')
            ->find();
        if (!$row || (int) $row['learner_id'] !== $learnerId) {
            return null;
        }
        return $this->shapeOrder($row);
    }

    /**
     * Mark an order as succeeded. Idempotent — second call is a no-op.
     * Stamps succeeded_at + creates the purchase entitlement in the
     * same transaction. Only PaymentAdapter::onNotify() should call
     * this; OrderController must not.
     */
    public function markSucceeded(int $orderId, string $providerRef): void
    {
        Db::transaction(function () use ($orderId, $providerRef) {
            $row = Db::name('orders')->where('id', $orderId)->lock(true)->find();
            if (!$row) {
                throw new BusinessException('NOT_FOUND', 'ORDER_NOT_FOUND');
            }
            if ((string) $row['status'] === 'succeeded') {
                // Idempotent re-delivery from the payment provider.
                return;
            }
            // Per rule 3: only pending orders can transition to succeeded.
            if ((string) $row['status'] !== 'pending') {
                Logger::warning('order.notify.skipped', [
                    'order_id' => $orderId,
                    'status'   => $row['status'],
                ]);
                return;
            }
            $nowDt = date('Y-m-d H:i:s');
            Db::name('orders')->where('id', $orderId)->update([
                'status'       => 'succeeded',
                'provider_ref' => $providerRef,
                'succeeded_at' => $nowDt,
                'updated_at'   => $nowDt,
            ]);
            $this->entitlements->grant(
                (int) $row['learner_id'],
                (int) $row['course_id'],
                'purchase',
                $orderId,
            );
            // 009-learner-coupons — redeem the locked coupon in the same
            // transaction so a half-committed payment can't leave the
            // coupon in 'locked' indefinitely.
            if ($this->coupons !== null) {
                $this->coupons->redeemOnSuccess($orderId);
            }
            Logger::info('order.succeeded', [
                'order_id'   => $orderId,
                'learner_id' => (int) $row['learner_id'],
                'course_id'  => (int) $row['course_id'],
            ]);
        });
    }

    /**
     * Mark an order as failed/cancelled/unknown. Idempotent. Does NOT
     * grant an entitlement. status must be one of failed|cancelled|unknown.
     */
    public function markFailed(int $orderId, string $status, ?string $providerRef = null): void
    {
        if (!in_array($status, ['failed', 'cancelled', 'unknown'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ORDER_STATUS_INVALID');
        }
        $nowDt = date('Y-m-d H:i:s');
        $patch = [
            'status'     => $status,
            'updated_at' => $nowDt,
        ];
        if ($providerRef !== null) {
            $patch['provider_ref'] = $providerRef;
        }
        Db::transaction(function () use ($orderId, $status, $patch) {
            $row = Db::name('orders')->where('id', $orderId)->lock(true)->find();
            if (!$row) {
                throw new BusinessException('NOT_FOUND', 'ORDER_NOT_FOUND');
            }
            if ((string) $row['status'] === 'succeeded') {
                // A succeeded order cannot be rolled back via notify.
                return;
            }
            if ((string) $row['status'] === $status) {
                return;
            }
            Db::name('orders')->where('id', $orderId)->update($patch);
            if ($this->coupons !== null) {
                $this->coupons->releaseOnTerminal($orderId);
            }
            Logger::info('order.' . $status, ['order_id' => $orderId]);
        });
    }

    /**
     * Cancel one batch of pending orders that reached the payment timeout.
     * The order row lock arbitrates races with payment callbacks.
     */
    public function cancelExpiredPending(int $timeoutMinutes = 15, int $batchSize = 200): int
    {
        $timeoutMinutes = max(1, $timeoutMinutes);
        $batchSize = max(1, min(200, $batchSize));
        $cutoff = date('Y-m-d H:i:s', time() - $timeoutMinutes * 60);
        $ids = Db::name('orders')
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->order('id', 'asc')
            ->limit($batchSize)
            ->column('id');

        $cancelled = 0;
        foreach ($ids as $id) {
            $changed = Db::transaction(function () use ($id, $cutoff): bool {
                $row = Db::name('orders')
                    ->where('id', (int) $id)
                    ->where('status', 'pending')
                    ->where('created_at', '<=', $cutoff)
                    ->lock(true)
                    ->find();
                if (!$row) {
                    return false;
                }

                Db::name('orders')->where('id', (int) $id)->update([
                    'status' => 'cancelled',
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);
                if ($this->coupons !== null) {
                    $this->coupons->releaseOnTerminal((int) $id);
                }
                Logger::info('order.cancelled', ['order_id' => (int) $id]);
                return true;
            });
            if ($changed) {
                $cancelled++;
            }
        }

        return $cancelled;
    }

    /**
     * Admin read-only listing (Phase 14 / US10 — T077).
     *
     * Read-only by design — there is no admin mark-as-paid path.
     * Payment state transitions are exclusively driven by
     * PaymentAdapter::onNotify() → markSucceeded / markFailed.
     */
    /** @return array<string, mixed> */
    public function adminList(
        int $staffId,
        ?string $status,
        ?int $courseId,
        ?int $learnerId,
        int $page,
        int $limit,
        DataScopeService $scope,
    ): array {
        $page = max(1, $page);
        $limit = max(1, min(200, $limit));
        $q = Db::name('orders')->alias('o')->join('courses c', 'o.course_id = c.id');
        if ($status !== null && $status !== '') {
            $q->where('o.status', $status);
        }
        if ($courseId !== null && $courseId > 0) {
            $q->where('o.course_id', $courseId);
        }
        if ($learnerId !== null && $learnerId > 0) {
            $q->where('o.learner_id', $learnerId);
        }
        $allowed = $scope->allowedDepartmentIds($staffId, 'order.view');
        if ($allowed !== null) {
            $q->where('c.department_id', 'in', $allowed);
        }
        $total = (clone $q)->count();
        $rows = $q
            ->field('o.*, c.title AS title, c.department_id AS department_id')
            ->order('o.id', 'desc')
            ->page($page, $limit)
            ->select()
            ->toArray();
        $items = array_map(fn($r) => $this->shapeAdminOrder($r), $rows);
        return [
            'items' => $items,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function adminShow(int $staffId, int $orderId, DataScopeService $scope): array
    {
        $row = Db::name('orders')->alias('o')
            ->join('courses c', 'o.course_id = c.id')
            ->where('o.id', $orderId)
            ->field('o.*, c.title AS title, c.department_id AS department_id')
            ->find();
        if (!$row) {
            throw new BusinessException('NOT_FOUND', 'ORDER_NOT_FOUND');
        }
        $allowed = $scope->allowedDepartmentIds($staffId, 'order.view');
        if ($allowed !== null && !in_array((int) $row['department_id'], $allowed, true)) {
            throw new BusinessException('FORBIDDEN', 'DEPARTMENT_OUT_OF_SCOPE');
        }
        return $this->shapeAdminOrder($row);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeOrder(array $row): array
    {
        return [
            'order_id'             => (int) $row['id'],
            'course_id'            => (int) $row['course_id'],
            'course_title'         => isset($row['course_title']) ? (string) $row['course_title'] : null,
            'list_price_snapshot'  => (float) $row['list_price_snapshot'],
            'sale_price_snapshot'  => (float) $row['sale_price_snapshot'],
            'coupon_discount_snapshot' => (float) ($row['coupon_discount_snapshot'] ?? 0),
            'paid_amount'          => (float) $row['paid_amount'],
            'learner_coupon_id'    => isset($row['learner_coupon_id'])
                ? (int) $row['learner_coupon_id']
                : null,
            'currency'             => (string) $row['currency'],
            'status'               => (string) $row['status'],
            'provider'             => (string) $row['provider'],
            'succeeded_at'         => $row['succeeded_at'] ? (string) $row['succeeded_at'] : null,
            'created_at'           => (string) $row['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeAdminOrder(array $row): array
    {
        $base = $this->shapeOrder($row);
        return [
            ...$base,
            'order_id'         => (int) ($row['o.id'] ?? $row['id'] ?? $base['order_id']),
            'learner_id'       => (int) $row['learner_id'],
            'department_id'    => isset($row['department_id']) ? (int) $row['department_id'] : null,
            'course_title'     => isset($row['title']) ? (string) $row['title'] : null,
            'provider_ref'     => isset($row['provider_ref']) ? (string) $row['provider_ref'] : null,
            'failed_reason'    => isset($row['failed_reason']) ? (string) $row['failed_reason'] : null,
        ];
    }
}
