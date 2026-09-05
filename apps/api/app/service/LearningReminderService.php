<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

/** Evaluates fixed learner reminders without duplicating learning state. */
final class LearningReminderService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const DAILY_LIMIT = 3;
    private const THROTTLE_HOURS = 72;

    public function __construct(
        private readonly MessageService $messages = new MessageService(),
        private readonly LearningActionService $actions = new LearningActionService(),
    ) {
    }

    /** @return array<string, mixed> */
    public function evaluateLearner(int $learnerId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $day = $now->format('Y-m-d');

        return Db::transaction(function () use ($learnerId, $now, $day): array {
            $learner = Db::name('accounts')
                ->where('id', $learnerId)
                ->where('kind', 'learner')
                ->where('status', 'active')
                ->lock(true)
                ->find();
            if (!$learner) {
                throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
            }

            $quietHours = $this->isQuietHours($now);
            $dailySent = $this->dailySentCount($learnerId, $now);
            $results = [];
            foreach ([
                'order_expiring' => $this->orderCandidate($learnerId, $now),
                'coupon_expiring' => $this->couponCandidate($learnerId, $now),
                'favorite_not_started' => $this->favoriteCandidate($learnerId, $now),
                'learning_inactive' => $this->inactiveCandidate($learnerId, $now),
            ] as $rule => $candidate) {
                if ($candidate === null) {
                    $results[] = $this->recordNotEligible($learnerId, (string) $rule, $now, $day);
                    continue;
                }
                $results[] = $this->evaluateCandidate(
                    $learnerId,
                    $candidate,
                    $now,
                    $day,
                    $quietHours,
                    $dailySent,
                );
                if (($results[array_key_last($results)]['status'] ?? null) === 'sent') {
                    $dailySent++;
                }
            }

            return [
                'learner_id' => $learnerId,
                'evaluated_at' => $now->format(DATE_ATOM),
                'quiet_hours' => $quietHours,
                'sent' => count(array_filter($results, static fn(array $r): bool => $r['status'] === 'sent')),
                'items' => $results,
            ];
        });
    }

    /** @return array<string, mixed> */
    public function evaluateBatch(int $batchSize = 200, ?\DateTimeImmutable $now = null): array
    {
        $batchSize = max(1, min(200, $batchSize));
        $processed = 0;
        $sent = 0;
        $failed = 0;
        $cursor = 0;
        while (true) {
            $ids = Db::name('accounts')
                ->where('id', '>', $cursor)
                ->where('kind', 'learner')
                ->where('status', 'active')
                ->order('id', 'asc')
                ->limit($batchSize)
                ->column('id');
            if ($ids === []) {
                break;
            }
            foreach ($ids as $id) {
                $cursor = (int) $id;
                try {
                    $result = $this->evaluateLearner($cursor, $now);
                    $processed++;
                    $sent += (int) ($result['sent'] ?? 0);
                } catch (\Throwable) {
                    $processed++;
                    $failed++;
                }
            }
        }

        return [
            'processed' => $processed,
            'sent' => $sent,
            'failed' => $failed,
            'batch_size' => $batchSize,
        ];
    }

    /** @return array<string, mixed>|null */
    private function orderCandidate(int $learnerId, \DateTimeImmutable $now): ?array
    {
        $rows = Db::name('orders')
            ->where('learner_id', $learnerId)
            ->where('status', 'pending')
            ->order('created_at', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $selected = null;
        $deadline = null;
        foreach ($rows as $row) {
            $created = $this->parseTime((string) $row['created_at']);
            $candidateDeadline = OrderService::pendingDeadline($created);
            if ($now >= $candidateDeadline || $candidateDeadline > $now->modify('+24 hours')) {
                continue;
            }
            if ($deadline === null || $candidateDeadline < $deadline) {
                $selected = $row;
                $deadline = $candidateDeadline;
            }
        }
        if ($selected === null || $deadline === null) return null;
        $id = (int) $selected['id'];
        return $this->candidate(
            'order_expiring',
            'order:' . $id,
            'ORDER_EXPIRING',
            '订单即将截止',
            '订单即将截止，请在 ' . $deadline->format('Y-m-d H:i') . ' 前完成支付',
            'order',
            $id,
            '/me/orders/' . $id,
        );
    }

    /** @return array<string, mixed>|null */
    private function couponCandidate(int $learnerId, \DateTimeImmutable $now): ?array
    {
        $rows = Db::name('learner_coupons')->alias('lc')
            ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
            ->where('lc.learner_id', $learnerId)
            ->where('lc.status', 'unused')
            ->where('c.status', 'active')
            ->where('lc.expires_at', '>', $this->dbTime($now))
            ->where('lc.expires_at', '<', $this->dbTime($now->modify('+4 days')->setTime(0, 0)))
            ->field('lc.*, c.scope_type')
            ->order('lc.expires_at', 'asc')
            ->order('lc.id', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (!$this->couponHasPublishedCourse($row)) continue;
            $id = (int) $row['id'];
            return $this->candidate(
                'coupon_expiring',
                'coupon:' . $id,
                'COUPON_EXPIRES_WITHIN_3_LOCAL_DAYS',
                '优惠券即将到期',
                '你的优惠券将于 ' . $this->parseTime((string) $row['expires_at'])->format('Y-m-d H:i') . ' 到期',
                'coupon',
                $id,
                '/me/coupons',
            );
        }
        return null;
    }

    /** @param array<string, mixed> $coupon */
    private function couponHasPublishedCourse(array $coupon): bool
    {
        $scope = (string) ($coupon['scope_type'] ?? '');
        $query = Db::name('courses')->where('courses.status', 'published')->where('courses.price_mode', 'paid');
        if ($scope === 'all') {
            return $query->count() > 0;
        }
        if ($scope === 'course') {
            $query->join('coupon_campaign_courses ccc', 'ccc.course_id = courses.id')
                ->where('ccc.campaign_id', (int) $coupon['campaign_id']);
        } elseif ($scope === 'category') {
            $query->join('coupon_campaign_categories ccc', 'ccc.category_id = courses.category_id')
                ->where('ccc.campaign_id', (int) $coupon['campaign_id']);
        } else {
            return false;
        }
        return $query->count() > 0;
    }

    /** @return array<string, mixed>|null */
    private function favoriteCandidate(int $learnerId, \DateTimeImmutable $now): ?array
    {
        $row = Db::name('favorites')->alias('f')
            ->join('courses c', 'c.id = f.course_id')
            ->leftJoin('course_enrollments ce', 'ce.course_id = f.course_id AND ce.learner_id = f.learner_id')
            ->where('f.learner_id', $learnerId)
            ->where('f.created_at', '<=', $this->dbTime($now->modify('-24 hours')))
            ->where('c.status', 'published')
            ->whereNull('ce.id')
            ->field('f.id, f.course_id, c.title')
            ->order('f.created_at', 'asc')
            ->order('f.id', 'asc')
            ->find();
        if (!$row) return null;
        $courseId = (int) $row['course_id'];
        return $this->candidate(
            'favorite_not_started',
            'favorite:' . (int) $row['id'],
            'FAVORITE_COURSE_NOT_STARTED',
            '收藏课程还没有开始',
            '你收藏的《' . (string) $row['title'] . '》已经超过一天没有开始学习',
            'course',
            $courseId,
            '/courses/' . $courseId,
        );
    }

    /** @return array<string, mixed>|null */
    private function inactiveCandidate(int $learnerId, \DateTimeImmutable $now): ?array
    {
        $latest = Db::name('lesson_progresses')
            ->where('learner_id', $learnerId)
            ->order('updated_at', 'desc')
            ->value('updated_at');
        $threshold = $now->setTime(0, 0)->modify('-7 days');
        if ($latest !== null && $latest !== '' && (string) $latest !== '0' && $this->parseTime((string) $latest) >= $threshold) {
            return null;
        }
        $target = $this->actions->learningTarget($learnerId, $now);
        if ($target === null) return null;
        if (($target['type'] ?? null) === 'start_favorite_course') return null;
        $resource = $target['target'];
        return $this->candidate(
            'learning_inactive',
            'inactive:' . $this->weekStart($now),
            'LEARNING_INACTIVE_7_DAYS',
            '继续学习',
            '已经有 7 个自然日没有有效学习，继续完成你的学习目标',
            (string) $resource['resource_type'],
            $resource['resource_id'] !== null ? (int) $resource['resource_id'] : null,
            (string) $resource['path'],
        );
    }

    /** @return array<string, mixed> */
    private function candidate(
        string $ruleCode,
        string $candidateKey,
        string $reasonCode,
        string $title,
        string $body,
        string $resourceType,
        ?int $resourceId,
        string $resourcePath,
    ): array {
        return compact(
            'ruleCode',
            'candidateKey',
            'reasonCode',
            'title',
            'body',
            'resourceType',
            'resourceId',
            'resourcePath',
        );
    }

    /**
     * @param array<string, mixed> $candidate
     * @return array<string, mixed>
     */
    private function evaluateCandidate(
        int $learnerId,
        array $candidate,
        \DateTimeImmutable $now,
        string $day,
        bool $quietHours,
        int $dailySent,
    ): array {
        $rule = (string) $candidate['ruleCode'];
        $key = (string) $candidate['candidateKey'];
        $existing = Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('rule_code', $rule)
            ->where('candidate_key', $key)
            ->lock(true)
            ->find();

        if ($existing === null) {
            $id = (int) Db::name('learner_reminder_evaluations')->insertGetId([
                'learner_id' => $learnerId,
                'rule_code' => $rule,
                'candidate_key' => $key,
                'resource_type' => $candidate['resourceType'],
                'resource_id' => $candidate['resourceId'],
                'evaluation_day' => $day,
                'evaluation_status' => 'not_eligible',
                'reason_code' => 'NOT_EVALUATED',
                'last_evaluated_at' => $this->sqlTime($now),
                'first_sent_at' => null,
                'last_sent_at' => null,
                'send_count' => 0,
                'notification_id' => null,
                'suppressed_at' => null,
                'error_message' => null,
                'created_at' => $this->sqlTime($now),
                'updated_at' => $this->sqlTime($now),
            ]);
            $existing = Db::name('learner_reminder_evaluations')->where('id', $id)->find();
        }

        if (!$this->resourceAvailable($learnerId, $candidate, $now)) {
            $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'resource_unavailable', 'RESOURCE_UNAVAILABLE');
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'resource_unavailable'];
        }
        if ($quietHours) {
            $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'quiet_hours', 'QUIET_HOURS');
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'quiet_hours'];
        }
        if ((int) ($existing['send_count'] ?? 0) > 0 && $rule === 'favorite_not_started') {
            $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'throttled', 'FAVORITE_ALREADY_SENT');
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'throttled'];
        }
        if (
            !empty($existing['suppressed_at'])
            && substr((string) $existing['suppressed_at'], 0, 10) === $day
            && (int) ($existing['send_count'] ?? 0) === 0
        ) {
            $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'daily_cap', 'DAILY_CAP');
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'daily_cap'];
        }
        if (!empty($existing['last_sent_at'])) {
            $lastSent = $this->parseTime((string) $existing['last_sent_at']);
            $window = $rule === 'learning_inactive' ? $now->modify('-7 days') : $now->modify('-' . self::THROTTLE_HOURS . ' hours');
            if ($lastSent >= $window) {
                $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'throttled', 'REMINDER_THROTTLED');
                return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'throttled'];
            }
        }
        if ($dailySent >= self::DAILY_LIMIT) {
            $this->updateEvaluation((int) $existing['id'], $candidate, $now, $day, 'daily_cap', 'DAILY_CAP');
            Db::name('learner_reminder_evaluations')->where('id', (int) $existing['id'])->update([
                'suppressed_at' => $this->sqlTime($now),
            ]);
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'daily_cap'];
        }

        try {
            $notificationId = $this->messages->emit(
                MessageService::KIND_LEARNING_REMINDER,
                $learnerId,
                (string) $candidate['title'],
                (string) $candidate['body'],
                [
                    'rule_code' => $rule,
                    'reason_code' => (string) $candidate['reasonCode'],
                    'generated_at' => $now->format(DATE_ATOM),
                ],
                (string) $candidate['resourceType'],
                $candidate['resourceId'] !== null ? (int) $candidate['resourceId'] : null,
                'learning-reminder:' . $learnerId . ':' . $rule . ':' . $key,
            );
        } catch (\Throwable $exception) {
            Db::name('learner_reminder_evaluations')->where('id', (int) $existing['id'])->update([
                'evaluation_day' => $day,
                'evaluation_status' => 'failed',
                'reason_code' => 'MESSAGE_WRITE_FAILED',
                'last_evaluated_at' => $this->sqlTime($now),
                'error_message' => mb_substr($exception->getMessage(), 0, 500),
                'updated_at' => $this->sqlTime($now),
            ]);
            return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'failed'];
        }

        $sentAt = $this->sqlTime($now);
        Db::name('learner_reminder_evaluations')->where('id', (int) $existing['id'])->update([
            'resource_type' => $candidate['resourceType'],
            'resource_id' => $candidate['resourceId'],
            'evaluation_day' => $day,
            'evaluation_status' => 'sent',
            'reason_code' => (string) $candidate['reasonCode'],
            'last_evaluated_at' => $sentAt,
            'first_sent_at' => $existing['first_sent_at'] ?? $sentAt,
            'last_sent_at' => $sentAt,
            'send_count' => ((int) ($existing['send_count'] ?? 0)) + 1,
            'notification_id' => $notificationId,
            'suppressed_at' => null,
            'error_message' => null,
            'updated_at' => $sentAt,
        ]);
        return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'sent', 'notification_id' => $notificationId];
    }

    /** @param array<string, mixed> $candidate */
    private function resourceAvailable(int $learnerId, array $candidate, \DateTimeImmutable $now): bool
    {
        $type = (string) $candidate['resourceType'];
        $id = $candidate['resourceId'] === null ? 0 : (int) $candidate['resourceId'];
        if ($id <= 0) return false;
        return match ($type) {
            'course' => Db::name('courses')->where('id', $id)->where('status', 'published')->count() > 0,
            'lesson' => $this->lessonAvailable($learnerId, $id),
            'learning_map' => Db::name('learning_maps')->where('id', $id)->where('status', 'published')->count() > 0,
            'order' => $this->orderAvailable($learnerId, $id, $now),
            'coupon' => $this->couponAvailable($learnerId, $id, $now),
            default => false,
        };
    }

    private function lessonAvailable(int $learnerId, int $lessonId): bool
    {
        $row = Db::name('lessons')->alias('l')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->join('courses c', 'c.id = ch.course_id')
            ->where('l.id', $lessonId)
            ->where('l.status', 'enabled')
            ->where('ch.status', 'enabled')
            ->where('c.status', 'published')
            ->field('ch.course_id, l.is_preview')
            ->find();
        if (!$row) return false;
        return (int) $row['is_preview'] === 1 || Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', (int) $row['course_id'])
            ->where('status', 'active')
            ->count() > 0;
    }

    private function couponAvailable(int $learnerId, int $couponId, \DateTimeImmutable $now): bool
    {
        $row = Db::name('learner_coupons')->alias('lc')
            ->join('coupon_campaigns c', 'c.id = lc.campaign_id')
            ->where('lc.id', $couponId)
            ->where('lc.learner_id', $learnerId)
            ->where('lc.status', 'unused')
            ->where('lc.expires_at', '>', $this->dbTime($now))
            ->where('c.status', 'active')
            ->field('lc.*, c.scope_type')
            ->find();
        return $row !== null && $this->couponHasPublishedCourse($row);
    }

    private function orderAvailable(int $learnerId, int $orderId, \DateTimeImmutable $now): bool
    {
        $row = Db::name('orders')->where('id', $orderId)->where('learner_id', $learnerId)->where('status', 'pending')->find();
        if (!$row) return false;
        return OrderService::isPendingWithin(
            $this->parseTime((string) $row['created_at']),
            $now,
        );
    }

    /** @return array<string, mixed> */
    private function recordNotEligible(int $learnerId, string $rule, \DateTimeImmutable $now, string $day): array
    {
        $key = 'none:' . $day;
        $values = [
            'learner_id' => $learnerId,
            'rule_code' => $rule,
            'candidate_key' => $key,
            'resource_type' => null,
            'resource_id' => null,
            'evaluation_day' => $day,
            'evaluation_status' => 'not_eligible',
            'reason_code' => 'NO_CANDIDATE',
            'last_evaluated_at' => $this->sqlTime($now),
            'updated_at' => $this->sqlTime($now),
        ];
        $existing = Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('rule_code', $rule)
            ->where('candidate_key', $key)
            ->find();
        if ($existing === null) {
            Db::name('learner_reminder_evaluations')->insert(array_merge($values, [
                'first_sent_at' => null,
                'last_sent_at' => null,
                'send_count' => 0,
                'notification_id' => null,
                'suppressed_at' => null,
                'error_message' => null,
                'created_at' => $this->sqlTime($now),
            ]));
        } else {
            Db::name('learner_reminder_evaluations')->where('id', (int) $existing['id'])->update($values);
        }
        return ['rule_code' => $rule, 'candidate_key' => $key, 'status' => 'not_eligible'];
    }

    /** @param array<string, mixed> $candidate */
    private function updateEvaluation(int $id, array $candidate, \DateTimeImmutable $now, string $day, string $status, string $reason): void
    {
        Db::name('learner_reminder_evaluations')->where('id', $id)->update([
            'resource_type' => $candidate['resourceType'],
            'resource_id' => $candidate['resourceId'],
            'evaluation_day' => $day,
            'evaluation_status' => $status,
            'reason_code' => $reason,
            'last_evaluated_at' => $this->sqlTime($now),
            'updated_at' => $this->sqlTime($now),
        ]);
    }

    private function dailySentCount(int $learnerId, \DateTimeImmutable $now): int
    {
        $start = $now->setTime(0, 0);
        return (int) Db::name('learner_reminder_evaluations')
            ->where('learner_id', $learnerId)
            ->where('last_sent_at', '>=', $this->sqlTime($start))
            ->where('last_sent_at', '<', $this->sqlTime($start->modify('+1 day')))
            ->count();
    }

    private function isQuietHours(\DateTimeImmutable $now): bool
    {
        $hour = (int) $now->format('H');
        return $hour >= 22 || $hour < 8;
    }

    private function weekStart(\DateTimeImmutable $now): string
    {
        return $now->setTime(0, 0)->modify('-' . ((int) $now->format('N') - 1) . ' days')->format('Y-m-d');
    }

    private function parseTime(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    private function sqlTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function dbTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
