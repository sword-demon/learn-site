<?php

declare(strict_types=1);

namespace App\service;

use support\think\Db;

final class LearnerDetailService
{
    public function __construct(
        private readonly DataScopeService $scope,
    ) {
    }

    /**
     * @return array{learner:array<string,mixed>,items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listCourseProgress(int $staffId, int $learnerId, array $filters): array
    {
        $learner = $this->assertLearnerAccessible($staffId, $learnerId);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 20)));

        $query = Db::name('course_entitlements')
            ->alias('ce')
            ->join('courses c', 'c.id = ce.course_id')
            ->leftJoin('course_enrollments e', 'e.learner_id = ce.learner_id AND e.course_id = ce.course_id')
            ->where('ce.learner_id', $learnerId)
            ->where('ce.id', 'in', function ($latest) use ($learnerId): void {
                $latest->name('course_entitlements')
                    ->where('learner_id', $learnerId)
                    ->group('course_id')
                    ->field('MAX(id)');
            })
            ->field('c.id AS course_id, c.title AS course_title, c.department_id, c.created_by_staff_id, ce.source, ce.status AS entitlement_status, ce.created_at AS enrolled_at, ce.revoked_at, e.progress_percent, e.completed_at, e.last_lesson_id, e.updated_at AS enrollment_updated_at');

        $this->applyCourseScope($staffId, $query, 'c');

        $total = (int) (clone $query)->count();
        $rows = $query->order('ce.id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'learner' => $learner,
            'items' => array_map([$this, 'shapeCourseProgress'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array{learner:array<string,mixed>,items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listLessonRecords(int $staffId, int $learnerId, array $filters): array
    {
        $learner = $this->assertLearnerAccessible($staffId, $learnerId);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 20)));

        $query = Db::name('lesson_progresses')
            ->alias('lp')
            ->join('lessons l', 'l.id = lp.lesson_id')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->join('courses c', 'c.id = ch.course_id')
            ->where('lp.learner_id', $learnerId)
            ->field('c.id AS course_id, c.title AS course_title, c.department_id, c.created_by_staff_id, l.id AS lesson_id, l.title AS lesson_title, lp.opened_at, lp.completed, lp.completed_at, lp.updated_at');

        $this->applyCourseScope($staffId, $query, 'c');

        $total = (int) (clone $query)->count();
        $rows = $query->order('lp.updated_at', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'learner' => $learner,
            'items' => array_map([$this, 'shapeLessonRecord'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string,mixed> */
    private function assertLearnerAccessible(int $staffId, int $learnerId): array
    {
        if ($learnerId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }

        $row = Db::name('accounts')
            ->alias('a')
            ->join('learners l', 'l.account_id = a.id')
            ->where('a.id', $learnerId)
            ->where('a.kind', 'learner')
            ->field('a.id, a.login, a.status, l.nickname AS display_name')
            ->find();
        if (!$row) {
            throw new BusinessException('NOT_FOUND', 'LEARNER_NOT_FOUND');
        }

        $allowed = $this->scope->allowedDepartmentIds($staffId, 'learner.view');
        if ($allowed !== null) {
            if ($allowed === []) {
                throw new BusinessException('NOT_FOUND', 'LEARNER_NOT_FOUND');
            }
            $visible = (int) Db::name('course_entitlements')
                ->alias('ce')
                ->join('courses c', 'c.id = ce.course_id')
                ->where('ce.learner_id', $learnerId)
                ->where('c.department_id', 'in', $allowed)
                ->count();
            if ($visible === 0) {
                throw new BusinessException('NOT_FOUND', 'LEARNER_NOT_FOUND');
            }
        }

        $displayName = trim((string) ($row['display_name'] ?? ''));
        return [
            'account_id' => (int) $row['id'],
            'login' => (string) $row['login'],
            'display_name' => $displayName !== '' ? $displayName : (string) $row['login'],
            'status' => (string) $row['status'],
        ];
    }

    /**
     * @param \think\db\Query $query
     */
    private function applyCourseScope(int $staffId, mixed $query, string $courseAlias): void
    {
        $scope = $this->scope->resolveForCourses($staffId);
        if ($scope['all']) {
            return;
        }

        $query->where(function ($w) use ($scope, $courseAlias, $staffId): void {
            if ($scope['department_ids'] !== []) {
                $w->where($courseAlias . '.department_id', 'in', $scope['department_ids']);
            }
            if ($scope['include_self']) {
                if ($scope['department_ids'] !== []) {
                    $w->whereOr($courseAlias . '.created_by_staff_id', $staffId);
                } else {
                    $w->where($courseAlias . '.created_by_staff_id', $staffId);
                }
            }
            if ($scope['department_ids'] === [] && !$scope['include_self']) {
                $w->where($courseAlias . '.id', '<', 0);
            }
        });
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeCourseProgress(array $row): array
    {
        $progress = (int) ($row['progress_percent'] ?? 0);
        $learningStatus = $row['completed_at'] !== null || $progress >= 100
            ? 'completed'
            : ($progress > 0 ? 'in_progress' : 'not_started');

        return [
            'course_id' => (int) $row['course_id'],
            'course_title' => (string) $row['course_title'],
            'source' => (string) $row['source'],
            'entitlement_status' => (string) $row['entitlement_status'],
            'progress_percent' => $progress,
            'learning_status' => $learningStatus,
            'last_learning_at' => $row['last_lesson_id'] !== null && $row['enrollment_updated_at'] !== null
                ? (string) $row['enrollment_updated_at']
                : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'enrolled_at' => (string) $row['enrolled_at'],
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeLessonRecord(array $row): array
    {
        return [
            'course_id' => (int) $row['course_id'],
            'course_title' => (string) $row['course_title'],
            'lesson_id' => (int) $row['lesson_id'],
            'lesson_title' => (string) $row['lesson_title'],
            'opened_at' => $row['opened_at'] !== null ? (string) $row['opened_at'] : null,
            'completed' => (int) ($row['completed'] ?? 0) === 1,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'updated_at' => (string) $row['updated_at'],
        ];
    }
}
