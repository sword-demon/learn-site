<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

/** Computes one explainable learner action from current database facts. */
final class LearningActionService
{
    private const TIMEZONE = 'Asia/Shanghai';

    /** @return array<string, mixed> */
    public function nextAction(int $learnerId, ?\DateTimeImmutable $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $this->assertActiveLearner($learnerId);
        $generatedAt = $this->formatTime($now);
        $degraded = [];

        $sources = [
            ['orders', fn() => $this->pendingOrderAction($learnerId, $now, $generatedAt)],
            ['coupons', fn() => $this->expiringCouponAction($learnerId, $now, $generatedAt)],
            ['learning_progress', fn() => $this->continueLessonAction($learnerId, $generatedAt)],
            ['notifications', fn() => $this->unreadMessageAction($learnerId, $now, $generatedAt)],
            ['learning_maps', fn() => $this->mapAction($learnerId, $generatedAt)],
            ['favorites', fn() => $this->favoriteAction($learnerId, $generatedAt)],
        ];

        foreach ($sources as [$dependency, $producer]) {
            try {
                $action = $producer();
            } catch (\Throwable) {
                $degraded[] = $dependency;
                continue;
            }
            if ($action !== null) {
                return [
                    'state' => $degraded === [] ? 'ready' : 'degraded',
                    'action' => $action,
                    'fallback' => null,
                    'evaluated_at' => $generatedAt,
                    'degraded_dependencies' => $degraded,
                ];
            }
        }

        $fallback = $this->action(
            'browse_courses',
            7,
            'fallback_browse',
            'NO_ACTIONABLE_CANDIDATE',
            '浏览课程',
            '暂时没有待继续的学习任务',
            'course_list',
            null,
            '/',
            $generatedAt,
        );
        return [
            'state' => $degraded === [] ? 'ready' : 'degraded',
            'action' => $degraded === [] ? $fallback : null,
            'fallback' => $degraded === [] ? null : $fallback,
            'evaluated_at' => $generatedAt,
            'degraded_dependencies' => $degraded,
        ];
    }

    /** Return the highest-priority current learning target for reminders. */
    /** @return array<string, mixed>|null */
    public function learningTarget(int $learnerId, ?\DateTimeImmutable $now = null): ?array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        $this->assertActiveLearner($learnerId);
        $at = $this->formatTime($now);
        foreach ([
            fn() => $this->continueLessonAction($learnerId, $at),
            fn() => $this->mapAction($learnerId, $at),
            fn() => $this->favoriteAction($learnerId, $at),
        ] as $producer) {
            $action = $producer();
            if ($action !== null) return $action;
        }
        return null;
    }

    private function assertActiveLearner(int $learnerId): void
    {
        $valid = Db::name('accounts')
            ->where('id', $learnerId)
            ->where('kind', 'learner')
            ->where('status', 'active')
            ->count() > 0;
        if (!$valid) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
    }

    /** @return array<string, mixed>|null */
    private function pendingOrderAction(int $learnerId, \DateTimeImmutable $now, string $at): ?array
    {
        $rows = Db::name('orders')
            ->where('learner_id', $learnerId)
            ->where('status', 'pending')
            ->order('created_at', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        $candidate = null;
        $candidateDeadline = null;
        foreach ($rows as $row) {
            $createdAt = $this->parseTime((string) $row['created_at']);
            $deadline = OrderService::pendingDeadline($createdAt);
            if ($now >= $deadline || $deadline > $now->modify('+24 hours')) continue;
            if ($candidateDeadline === null || $deadline < $candidateDeadline) {
                $candidate = $row;
                $candidateDeadline = $deadline;
            }
        }
        if ($candidate === null || $candidateDeadline === null) return null;
        return $this->action(
            'pay_order',
            1,
            'order_expiring',
            'ORDER_EXPIRING',
            '继续支付订单',
            '订单即将截止，请在 ' . $candidateDeadline->format('Y-m-d H:i') . ' 前完成支付',
            'order',
            (int) $candidate['id'],
            '/me/orders/' . (int) $candidate['id'],
            $at,
        );
    }

    /** @return array<string, mixed>|null */
    private function expiringCouponAction(int $learnerId, \DateTimeImmutable $now, string $at): ?array
    {
        $rows = Db::name('learner_coupons')->alias('lc')
            ->join('coupon_campaigns cc', 'cc.id = lc.campaign_id')
            ->where('lc.learner_id', $learnerId)
            ->where('lc.status', 'unused')
            ->where('cc.status', 'active')
            ->where('lc.expires_at', '>', $this->dbTime($now))
            ->where('lc.expires_at', '<', $this->dbTime($now->modify('+4 days')->setTime(0, 0)))
            ->field('lc.*, cc.name, cc.scope_type')
            ->order('lc.expires_at', 'asc')
            ->order('lc.id', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            if (!$this->couponHasPublishedCourse($row)) continue;
            return $this->action(
                'use_coupon',
                2,
                'coupon_expiring',
                'COUPON_EXPIRES_WITHIN_3_LOCAL_DAYS',
                '优惠券即将到期',
                '你的优惠券将于 ' . $this->parseTime((string) $row['expires_at'])->format('Y-m-d H:i') . ' 到期',
                'coupon',
                (int) $row['id'],
                '/me/coupons',
                $at,
            );
        }
        return null;
    }

    /** @param array<string, mixed> $coupon */
    private function couponHasPublishedCourse(array $coupon): bool
    {
        $scope = (string) ($coupon['scope_type'] ?? '');
        if ($scope === 'all') {
            return Db::name('courses')->where('status', 'published')->where('price_mode', 'paid')->count() > 0;
        }
        $query = Db::name('courses')->where('courses.status', 'published')->where('courses.price_mode', 'paid');
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
    private function continueLessonAction(int $learnerId, string $at): ?array
    {
        $entitlements = Db::name('course_entitlements')->alias('ce')
            ->join('courses c', 'c.id = ce.course_id')
            ->where('ce.learner_id', $learnerId)
            ->where('ce.status', 'active')
            ->where('c.status', 'published')
            ->field('ce.course_id, c.title')
            ->select()
            ->toArray();
        usort($entitlements, function (array $a, array $b) use ($learnerId): int {
            $score = static function (array $row) use ($learnerId): string {
                $courseId = (int) $row['course_id'];
                return (string) (Db::name('lesson_progresses')->alias('lp')
                    ->join('lessons l', 'l.id = lp.lesson_id')
                    ->join('chapters ch', 'ch.id = l.chapter_id')
                    ->where('lp.learner_id', $learnerId)->where('ch.course_id', $courseId)
                    ->max('lp.updated_at') ?? '0000-00-00 00:00:00');
            };
            return strcmp($score($b), $score($a)) ?: ((int) $a['course_id'] <=> (int) $b['course_id']);
        });
        foreach ($entitlements as $entitlement) {
            $courseId = (int) $entitlement['course_id'];
            $enrollment = Db::name('course_enrollments')
                ->where('learner_id', $learnerId)
                ->where('course_id', $courseId)
                ->find();
            if ($enrollment && $enrollment['completed_at'] !== null) continue;
            $lessons = Db::name('lessons')->alias('l')
                ->join('chapters ch', 'ch.id = l.chapter_id')
                ->where('ch.course_id', $courseId)
                ->where('ch.status', 'enabled')
                ->where('l.status', 'enabled')
                ->field('l.id, l.title, l.sort, ch.sort AS chapter_sort')
                ->order('ch.sort', 'asc')
                ->order('l.sort', 'asc')
                ->order('l.id', 'asc')
                ->select()
                ->toArray();
            if ($lessons === []) continue;
            $progress = Db::name('lesson_progresses')
                ->where('learner_id', $learnerId)
                ->where('lesson_id', 'in', array_column($lessons, 'id'))
                ->select()
                ->toArray();
            $byLesson = [];
            foreach ($progress as $row) $byLesson[(int) $row['lesson_id']] = $row;
            $target = null;
            if ($enrollment && $enrollment['last_lesson_id'] !== null) {
                $last = $byLesson[(int) $enrollment['last_lesson_id']] ?? null;
                if ($last && (int) $last['completed'] === 0) {
                    foreach ($lessons as $lesson) {
                        if ((int) $lesson['id'] === (int) $enrollment['last_lesson_id']) $target = $lesson;
                    }
                }
            }
            if ($target === null) {
                foreach ($lessons as $lesson) {
                    if ((int) ($byLesson[(int) $lesson['id']]['completed'] ?? 0) === 0) {
                        $target = $lesson;
                        break;
                    }
                }
            }
            if ($target === null) continue;
            return $this->action(
                'continue_lesson',
                3,
                'continue_authorized_lesson',
                $enrollment && $enrollment['last_lesson_id'] !== null ? 'CONTINUE_LAST_LESSON' : 'START_AUTHORIZED_COURSE',
                '继续学习：' . (string) $target['title'],
                $enrollment && $enrollment['last_lesson_id'] !== null ? '继续上次未完成的课节' : '开始已获得访问权的课程',
                'lesson',
                (int) $target['id'],
                '/learn/' . $courseId . '/' . (int) $target['id'],
                $at,
            );
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function unreadMessageAction(int $learnerId, \DateTimeImmutable $now, string $at): ?array
    {
        $rows = Db::name('learner_notifications')
            ->where('learner_id', $learnerId)
            ->whereNull('read_at')
            ->whereIn('resource_type', ['course', 'lesson', 'learning_map', 'order', 'coupon'])
            ->order('id', 'desc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $type = (string) $row['resource_type'];
            $id = $row['resource_id'] === null ? null : (int) $row['resource_id'];
            $path = $this->resourcePath($learnerId, $type, $id, $now);
            if ($path === null) continue;
            return $this->action(
                'open_message',
                4,
                'unread_message',
                'UNREAD_RESOURCE_MESSAGE',
                (string) $row['title'],
                $row['body'] !== null ? (string) $row['body'] : '有一条待处理的学习消息',
                $type,
                $id,
                $path,
                $at,
            );
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function mapAction(int $learnerId, string $at): ?array
    {
        $rows = Db::name('map_enrollments')->alias('me')
            ->join('learning_maps m', 'm.id = me.map_id')
            ->join('map_stage_courses msc', 'msc.map_id = m.id')
            ->join('map_stages ms', 'ms.id = msc.stage_id')
            ->join('courses c', 'c.id = msc.course_id')
            ->where('me.learner_id', $learnerId)
            ->where('m.status', 'published')
            ->where('c.status', 'published')
            ->field('m.id AS map_id, m.title AS map_title, me.enrolled_at, ms.sort_order AS stage_sort, msc.sort_order AS course_sort, msc.course_id, c.title AS course_title')
            ->order('me.enrolled_at', 'desc')
            ->order('m.id', 'asc')
            ->order('ms.sort_order', 'asc')
            ->order('msc.sort_order', 'asc')
            ->order('msc.id', 'asc')
            ->select()
            ->toArray();
        foreach ($rows as $row) {
            $completed = Db::name('course_enrollments')
                ->where('learner_id', $learnerId)
                ->where('course_id', (int) $row['course_id'])
                ->whereNotNull('completed_at')
                ->count() > 0;
            if ($completed) continue;
            $authorized = Db::name('course_entitlements')
                ->where('learner_id', $learnerId)
                ->where('course_id', (int) $row['course_id'])
                ->where('status', 'active')
                ->count() > 0;
            $targetType = $authorized ? 'learning_map' : 'course';
            $targetId = $authorized ? (int) $row['map_id'] : (int) $row['course_id'];
            $targetPath = $authorized
                ? '/maps/' . (int) $row['map_id']
                : '/courses/' . (int) $row['course_id'];
            return $this->action(
                'continue_map',
                5,
                'continue_learning_map',
                'MAP_NEXT_STEP',
                '继续学习地图：' . (string) $row['course_title'],
                '这是学习地图中的下一步',
                $targetType,
                $targetId,
                $targetPath,
                $at,
                $authorized ? 'available' : 'requires_access',
                $authorized ? null : '需要取得课程访问权后才能学习',
            );
        }
        return null;
    }

    /** @return array<string, mixed>|null */
    private function favoriteAction(int $learnerId, string $at): ?array
    {
        $rows = Db::name('favorites')->alias('f')
            ->join('courses c', 'c.id = f.course_id')
            ->leftJoin('course_enrollments ce', 'ce.course_id = f.course_id AND ce.learner_id = f.learner_id')
            ->where('f.learner_id', $learnerId)
            ->where('c.status', 'published')
            ->whereNull('ce.id')
            ->field('f.course_id, c.title, f.created_at')
            ->order('f.created_at', 'desc')
            ->order('f.id', 'asc')
            ->select()
            ->toArray();
        $row = $rows[0] ?? null;
        if ($row === null) return null;
        return $this->action(
            'start_favorite_course',
            6,
            'favorite_not_started',
            'FAVORITE_COURSE_NOT_STARTED',
            '开始收藏课程：' . (string) $row['title'],
            '你收藏了这门还没有开始的课程',
            'course',
            (int) $row['course_id'],
            '/courses/' . (int) $row['course_id'],
            $at,
            'requires_access',
            '课程详情可访问，学习内容需要取得访问权',
        );
    }

    private function resourcePath(int $learnerId, string $type, ?int $id, ?\DateTimeImmutable $now = null): ?string
    {
        if ($id === null || $id <= 0) return null;
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE));
        $now = $now->setTimezone(new \DateTimeZone(self::TIMEZONE));
        return match ($type) {
            'course' => Db::name('courses')->where('id', $id)->where('status', 'published')->count() > 0 ? '/courses/' . $id : null,
            'lesson' => $this->lessonResourcePath($learnerId, $id),
            'learning_map' => Db::name('learning_maps')->where('id', $id)->where('status', 'published')->count() > 0 ? '/maps/' . $id : null,
            'order' => $this->pendingOrderAvailable($learnerId, $id, $now) ? '/me/orders/' . $id : null,
            'coupon' => $this->couponAvailable($learnerId, $id, $now) ? '/me/coupons' : null,
            default => null,
        };
    }

    private function pendingOrderAvailable(int $learnerId, int $orderId, \DateTimeImmutable $now): bool
    {
        $row = Db::name('orders')->where('id', $orderId)->where('learner_id', $learnerId)->where('status', 'pending')->find();
        if (!$row) return false;
        return OrderService::isPendingWithin(
            $this->parseTime((string) $row['created_at']),
            $now,
        );
    }

    private function couponAvailable(int $learnerId, int $couponId, \DateTimeImmutable $now): bool
    {
        $row = Db::name('learner_coupons')->alias('lc')
            ->join('coupon_campaigns cc', 'cc.id = lc.campaign_id')
            ->where('lc.id', $couponId)->where('lc.learner_id', $learnerId)
            ->where('lc.status', 'unused')->where('cc.status', 'active')
            ->where('lc.expires_at', '>', $this->dbTime($now))
            ->field('lc.*, cc.scope_type')
            ->find();
        return $row !== null && $this->couponHasPublishedCourse($row);
    }

    private function lessonResourcePath(int $learnerId, int $lessonId): ?string
    {
        $row = Db::name('lessons')->alias('l')->join('chapters ch', 'ch.id = l.chapter_id')->join('courses c', 'c.id = ch.course_id')
            ->where('l.id', $lessonId)->where('l.status', 'enabled')->where('ch.status', 'enabled')->where('c.status', 'published')
            ->field('ch.course_id, l.is_preview')->find();
        if (!$row) return null;
        $authorized = (int) $row['is_preview'] === 1 || Db::name('course_entitlements')->where('learner_id', $learnerId)->where('course_id', (int) $row['course_id'])->where('status', 'active')->count() > 0;
        return $authorized ? '/learn/' . (int) $row['course_id'] . '/' . $lessonId : null;
    }

    /** @return array<string, mixed> */
    private function action(string $type, int $priority, string $rule, string $reasonCode, string $title, string $reason, string $resourceType, ?int $resourceId, string $path, string $at, string $availability = 'available', ?string $availabilityReason = null): array
    {
        return [
            'type' => $type,
            'priority' => $priority,
            'rule_code' => $rule,
            'reason_code' => $reasonCode,
            'title' => $title,
            'reason' => $reason,
            'target' => ['resource_type' => $resourceType, 'resource_id' => $resourceId, 'path' => $path],
            'availability' => $availability,
            'availability_reason' => $availabilityReason,
            'generated_at' => $at,
        ];
    }

    private function parseTime(string $value): \DateTimeImmutable
    {
        return (new \DateTimeImmutable($value, new \DateTimeZone('UTC')))
            ->setTimezone(new \DateTimeZone(self::TIMEZONE));
    }

    private function formatTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone(self::TIMEZONE))->format('c');
    }

    private function dbTime(\DateTimeImmutable $value): string
    {
        return $value->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
