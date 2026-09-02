<?php
declare(strict_types=1);

namespace App\service;

use App\model\CourseEntitlement;
use App\support\Logger;
use support\think\Db;
use think\db\exception\PDOException as ThinkPdoException;

/**
 * EntitlementService — grants and authorization checks for learners.
 *
 * Phase 6 (US3). Two entry points:
 *   - viewerAuthorized(): read-only, called from PublicCatalogService and
 *     PublicLessonService to decide whether a course/lesson should be
 *     locked or open. Returns true only when an active row exists.
 *   - grant(): write — issues a `source='free'` entitlement immediately
 *     (POST /courses/{id}/start for free courses), a `source='purchase'`
 *     entitlement once a paid order transitions to succeeded, or a
 *     `source='activation_code'` entitlement when a learner redeems a
 *     valid code (010-course-notify-feedback-codes). revoke() is
 *     reserved for admin/Phase 10 (dept + audit) and is left as a stub
 *     here for completeness.
 *
 * Invariants enforced here (and at the schema level where possible):
 *   1. A learner holds at most one ACTIVE entitlement per course. The
     *      active-only unique index covers learner/course, so a duplicate
     *      active grant from a race is rejected by the DB. Revoked history is
     *      intentionally excluded from that index.
 *   2. Revoked entitlements remain in the table for audit; re-join creates
 *      a new row, never re-activates an old one.
 *   3. grant() is idempotent for free/purchase grants. Activation-code
 *      grants reject an existing active entitlement so a losing code is
 *      never consumed by the caller.
 */
final class EntitlementService
{
    /**
     * Read-only authorization check. Cheap single-row lookup against
     * course_entitlements. Returns true when an active row exists; false
     * for absent / revoked / null viewer.
     *
     * PublicCatalogService and PublicLessonService use this to decide
     * whether lesson rows are locked and whether full-lesson delivery is
     * allowed (FR-007).
     */
    public function viewerAuthorized(int $courseId, ?int $viewerAccountId): bool
    {
        if ($viewerAccountId === null || $viewerAccountId <= 0) {
            return false;
        }
        try {
            $count = (int) Db::name('course_entitlements')
                ->where('learner_id', $viewerAccountId)
                ->where('course_id', $courseId)
                ->where('status', 'active')
                ->count();
        } catch (\Throwable) {
            // ponytail: defensive — migration may not have run yet on a
            // warm-up boot in dev. Treat as not-yet-authorised so the
            // AccessGate renders the locked CTA consistently.
            return false;
        }
        return $count > 0;
    }

    /**
     * Insert-first grant. The active-entitlement unique index arbitrates
     * races without locking a missing row first.
     *
     * NOTE for activation-code redemption: duplicate active grants are a
     * conflict, not an idempotent success. This makes the losing redemption
     * roll back so its activation code remains unused (FR-015).
     *
     * @param 'free'|'purchase'|'activation_code' $source
     * @return array{ id:int, learner_id:int, course_id:int, source:string, order_id:?int, activation_code_id:?int, status:string, created_at:string, updated_at:string }
     */
    public function grant(
        int $learnerId,
        int $courseId,
        string $source,
        ?int $orderId = null,
        ?int $activationCodeId = null,
    ): array {
        if ($learnerId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'LEARNER_INVALID');
        }
        if ($courseId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_INVALID');
        }
        if ($source !== 'free' && $source !== 'purchase' && $source !== 'activation_code') {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_SOURCE_INVALID');
        }
        if ($source === 'purchase' && ($orderId === null || $orderId <= 0)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_ORDER_REQUIRED');
        }
        if ($source === 'activation_code' && ($orderId !== null || $activationCodeId === null || $activationCodeId <= 0)) {
            // 兑换产生的访问权不挂订单,且必须能追溯到那一枚码。
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_CODE_REQUIRED');
        }

        $now = date('Y-m-d H:i:s');

        return Db::transaction(function () use ($learnerId, $courseId, $source, $orderId, $activationCodeId, $now) {
            $insert = [
                'learner_id' => $learnerId,
                'course_id'  => $courseId,
                'source'     => $source,
                'order_id'   => $orderId,
                'activation_code_id' => $activationCodeId,
                'status'     => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ];
            try {
                $id = (int) Db::name('course_entitlements')->insertGetId($insert);
            } catch (\Throwable $e) {
                if (!$this->isDuplicateKey($e)) {
                    throw $e;
                }
                $row = Db::name('course_entitlements')
                    ->where('learner_id', $learnerId)
                    ->where('course_id', $courseId)
                    ->where('status', 'active')
                    ->find();
                if ($row) {
                    if ($source === 'activation_code') {
                        throw new BusinessException('CONFLICT', 'ENTITLEMENT_ALREADY_ACTIVE');
                    }
                    $this->ensureEnrollment($learnerId, $courseId, $now);
                    return $this->shape($row);
                }
                throw $e;
            }
            $this->ensureEnrollment($learnerId, $courseId, $now);
            Logger::info('entitlement.granted', [
                'entitlement_id' => $id,
                'learner_id'     => $learnerId,
                'course_id'      => $courseId,
                'source'         => $source,
                'order_id'       => $orderId,
            ]);
            return $this->shape(array_merge($insert, ['id' => $id]));
        });
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        if ($exception instanceof ThinkPdoException) {
            $info = $exception->getData()['PDO Error Info'] ?? [];
            return ($info['SQLSTATE'] ?? null) === '23000'
                && (int) ($info['Driver Error Code'] ?? 0) === 1062;
        }
        if ($exception instanceof \PDOException) {
            return ($exception->errorInfo[0] ?? null) === '23000'
                && (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }
        return false;
    }

    /** Ensure every active grant has its aggregate learning row. */
    private function ensureEnrollment(int $learnerId, int $courseId, string $now): void
    {
        $existing = Db::name('course_enrollments')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->lock(true)
            ->find();
        if ($existing) {
            return;
        }

        Db::name('course_enrollments')->insert([
            'learner_id'       => $learnerId,
            'course_id'        => $courseId,
            'progress_percent' => 0,
            'last_lesson_id'   => null,
            'last_position'    => 0,
            'completed_at'     => null,
            'created_at'       => $now,
            'updated_at'       => $now,
        ]);
    }

    /**
     * Revoke an active grant. Used when an admin invalidates a paid grant
     * (e.g. chargeback). The course_entitlements row is stamped revoked;
     * we never delete, so the order remains auditable.
     *
     * Side effect (T104): when the revoke actually flips a row, an inbox
     * notification of kind `entitlement_revoked` is queued for the learner
     * via MessageService. Title carries the course name when we can fetch
     * it cheaply; otherwise a generic label is used.
     *
     * @param int|null $revokedByStaffId staff account performing the revoke; null = self-service / system.
     */
    /** @return array<string, mixed>|null */
    public function revoke(int $learnerId, int $courseId, string $reason, ?int $revokedByStaffId = null): ?array
    {
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('VALIDATION_FAILED', 'REVOKE_REASON_REQUIRED');
        }
        if (mb_strlen($reason) > 255) {
            throw new BusinessException('VALIDATION_FAILED', 'REASON_TOO_LONG');
        }
        $now = date('Y-m-d H:i:s');
        $revoked = Db::transaction(function () use ($learnerId, $courseId, $reason, $revokedByStaffId, $now): ?array {
            $entitlement = CourseEntitlement::where('learner_id', $learnerId)
                ->where('course_id', $courseId)
                ->where('status', 'active')
                ->lock(true)
                ->find();
            if (!$entitlement) {
                return null;
            }
            if (!$entitlement->isRevocable()) {
                throw new BusinessException('FORBIDDEN', 'PAID_NOT_REVOCABLE');
            }

            CourseEntitlement::where('id', (int) $entitlement->id)
                ->where('status', 'active')
                ->update([
                    'status' => 'revoked',
                    'revoked_at' => $now,
                    'revoked_reason' => $reason,
                    'revoked_by_staff_id' => $revokedByStaffId,
                    'updated_at' => $now,
                ]);

            return array_merge($entitlement->toArray(), [
                'status' => 'revoked',
                'revoked_at' => $now,
                'revoked_reason' => $reason,
                'revoked_by_staff_id' => $revokedByStaffId,
                'updated_at' => $now,
            ]);
        });
        if ($revoked === null) {
            return null;
        }

        Logger::warning('entitlement.revoked', [
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'reason' => $reason,
            'revoked_by' => $revokedByStaffId,
        ]);
        // The inbox is best-effort; authorization revocation must not depend
        // on a secondary notification write.
        try {
            $courseName = (string) (Db::name('courses')->where('id', $courseId)->value('title') ?? '');
            $title = $courseName !== '' ? "「{$courseName}」的授权已被撤销" : '您的课程授权已被撤销';
            (new MessageService())->emit(
                MessageService::KIND_ENTITLEMENT_REVOKED,
                $learnerId,
                $title,
                '原因：' . $reason,
                ['course_id' => $courseId, 'reason' => $reason, 'revoked_by' => $revokedByStaffId],
                'course',
                $courseId,
                'entitlement_revoked:' . (int) $revoked['id'],
            );
        } catch (\Throwable $e) {
            Logger::warning('entitlement.notification_failed', [
                'learner_id' => $learnerId,
                'course_id' => $courseId,
                'err' => $e->getMessage(),
            ]);
        }

        return $revoked;
    }

    /**
     * Lookup the active entitlement row, or null. Used by the start-free
     * flow to decide between 200-with-entitlement vs 409 already-entitled.
     */
    /** @return array<string, mixed>|null */
    public function findActive(int $learnerId, int $courseId): ?array
    {
        $row = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->find();
        return $row ? $this->shape($row) : null;
    }

    /** @return array<string, mixed>|null */
    public function findLatest(int $learnerId, int $courseId): ?array
    {
        $row = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->order('id', 'desc')
            ->find();
        if (!$row) {
            return null;
        }
        return array_merge($this->shape($row), [
            'revoked_at' => $row['revoked_at'] ? (string) $row['revoked_at'] : null,
            'revoked_reason' => $row['revoked_reason'] ? (string) $row['revoked_reason'] : null,
            'revoked_by_staff_id' => isset($row['revoked_by_staff_id']) && $row['revoked_by_staff_id'] !== null
                ? (int) $row['revoked_by_staff_id']
                : null,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shape(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'learner_id' => (int) $row['learner_id'],
            'course_id'  => (int) $row['course_id'],
            'source'     => (string) $row['source'],
            'order_id'   => isset($row['order_id']) && $row['order_id'] !== null ? (int) $row['order_id'] : null,
            'activation_code_id' => isset($row['activation_code_id']) && $row['activation_code_id'] !== null
                ? (int) $row['activation_code_id']
                : null,
            'status'     => (string) $row['status'],
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
        ];
    }
}
