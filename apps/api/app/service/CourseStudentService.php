<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;

final class CourseStudentService
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly EntitlementService $entitlements,
    ) {
    }

    /**
     * @param array<string,mixed> $filters
     * @return array{items:list<array<string,int|string|null>>,total:int,page:int,limit:int}
     */
    public function listForCourse(int $staffId, int $courseId, array $filters): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, ['active', 'revoked'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_STATUS_INVALID');
        }
        $source = (string) ($filters['source'] ?? '');
        if ($source !== '' && !in_array($source, ['free', 'purchase', 'activation_code'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'ENTITLEMENT_SOURCE_INVALID');
        }
        $learningStatus = (string) ($filters['learning_status'] ?? '');
        if ($learningStatus !== '' && !in_array($learningStatus, ['not_started', 'in_progress', 'completed'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'LEARNING_STATUS_INVALID');
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 20)));
        $query = Db::name('course_entitlements')
            ->alias('ce')
            ->join('learners l', 'l.account_id = ce.learner_id')
            ->join('accounts a', 'a.id = ce.learner_id')
            ->leftJoin('course_enrollments e', 'e.learner_id = ce.learner_id AND e.course_id = ce.course_id')
            ->where('ce.course_id', $courseId)
            ->where('ce.id', 'in', function ($latest) use ($courseId): void {
                $latest->name('course_entitlements')
                    ->where('course_id', $courseId)
                    ->group('learner_id')
                    ->field('MAX(id)');
            })
            ->field('a.id AS account_id, a.login, a.status AS account_status, a.last_login_at, l.nickname, ce.source, ce.status AS entitlement_status, ce.created_at AS enrolled_at, ce.revoked_at, ce.revoked_reason, e.progress_percent, e.completed_at, e.last_lesson_id, e.updated_at AS enrollment_updated_at');
        if ($status !== '') {
            $query->where('ce.status', $status);
        }
        if ($source !== '') {
            $query->where('ce.source', $source);
        }
        if ($learningStatus === 'not_started') {
            $query->whereRaw('(e.id IS NULL OR e.progress_percent = 0)');
        } elseif ($learningStatus === 'in_progress') {
            $query->whereRaw('e.progress_percent > 0 AND e.progress_percent < 100 AND e.completed_at IS NULL');
        } elseif ($learningStatus === 'completed') {
            $query->whereRaw('(e.completed_at IS NOT NULL OR e.progress_percent >= 100)');
        }
        $total = (int) (clone $query)->count();
        $rows = $query->order('ce.id', 'desc')->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map([$this, 'shapeItem'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array{revoked:true} */
    public function revokeFree(int $staffId, int $courseId, int $learnerId, string $reason): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $reason = trim($reason);
        if ($reason === '') {
            throw new BusinessException('VALIDATION_FAILED', 'REVOKE_REASON_REQUIRED');
        }
        if (mb_strlen($reason) > 255) {
            throw new BusinessException('VALIDATION_FAILED', 'REASON_TOO_LONG');
        }
        $row = $this->entitlements->revoke($learnerId, $courseId, $reason, $staffId);
        if ($row === null) {
            throw new BusinessException('NOT_FOUND', 'NO_ACTIVE_ENTITLEMENT');
        }
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => 'course_student.revoke_free',
            'target_type' => 'course_entitlement',
            'target_id' => (int) $row['id'],
            'payload_json' => json_encode([
                'course_id' => $courseId,
                'learner_id' => $learnerId,
                'reason' => $reason,
            ], JSON_UNESCAPED_UNICODE),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::info('course_student.revoked', [
            'actor_id' => $staffId,
            'course_id' => $courseId,
            'learner_id' => $learnerId,
        ]);
        return ['revoked' => true];
    }

    /** @return array{reset:true} */
    public function resetProgress(int $staffId, int $courseId, int $learnerId): array
    {
        $course = $this->assertCourseAccessible($staffId, $courseId);
        $enrollment = Db::name('course_enrollments')
            ->where('course_id', $courseId)
            ->where('learner_id', $learnerId)
            ->find();
        if (!$enrollment) {
            throw new BusinessException('NOT_FOUND', 'COURSE_ENROLLMENT_NOT_FOUND');
        }

        Db::transaction(function () use ($staffId, $courseId, $learnerId, $course, $enrollment): void {
            Db::name('lesson_progresses')
                ->where('learner_id', $learnerId)
                ->where('lesson_id', 'in', function ($query) use ($courseId): void {
                    $query->name('lessons')
                        ->where('chapter_id', 'in', function ($chapters) use ($courseId): void {
                            $chapters->name('chapters')->where('course_id', $courseId)->field('id');
                        })
                        ->field('id');
                })
                ->delete();
            $now = date('Y-m-d H:i:s');
            Db::name('course_enrollments')->where('id', (int) $enrollment['id'])->update([
                'progress_percent' => 0,
                'last_lesson_id' => null,
                'last_position' => 0,
                'completed_at' => null,
                'updated_at' => $now,
            ]);
            $auditId = (int) Db::name('audit_log')->insertGetId([
                'actor_id' => $staffId,
                'action' => 'course_student.progress_reset',
                'target_type' => 'course_enrollment',
                'target_id' => (int) $enrollment['id'],
                'payload_json' => json_encode([
                    'course_id' => $courseId,
                    'learner_id' => $learnerId,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => $now,
            ]);
            (new MessageService())->emit(
                MessageService::KIND_PROGRESS_RESET,
                $learnerId,
                '课程学习进度已重置',
                '「' . (string) ($course['title'] ?? '课程') . '」的学习进度已由管理员重置。',
                ['course_id' => $courseId],
                'course',
                $courseId,
                'progress_reset:' . $auditId,
            );
        });
        Logger::info('course_student.progress_reset', [
            'actor_id' => $staffId,
            'course_id' => $courseId,
            'learner_id' => $learnerId,
        ]);
        return ['reset' => true];
    }

    /** @return array<string,mixed> */
    private function assertCourseAccessible(int $staffId, int $courseId): array
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, title, department_id, created_by_staff_id')
            ->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $course['department_id'],
            (int) $course['created_by_staff_id'],
            $staffId,
        );
        return $course;
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,int|string|null>
     */
    private function shapeItem(array $row): array
    {
        $nickname = trim((string) ($row['nickname'] ?? ''));
        $progress = (int) ($row['progress_percent'] ?? 0);
        $learningStatus = $row['completed_at'] !== null || $progress >= 100
            ? 'completed'
            : ($progress > 0 ? 'in_progress' : 'not_started');
        return [
            'account_id' => (int) $row['account_id'],
            'login' => (string) $row['login'],
            'nickname' => $nickname !== '' ? $nickname : '匿名学员',
            'account_status' => (string) $row['account_status'],
            'source' => (string) $row['source'],
            'entitlement_status' => (string) $row['entitlement_status'],
            'progress_percent' => $progress,
            'learning_status' => $learningStatus,
            'last_learning_at' => $row['last_lesson_id'] !== null && $row['enrollment_updated_at'] !== null
                ? (string) $row['enrollment_updated_at']
                : null,
            'completed_at' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
            'enrolled_at' => (string) $row['enrolled_at'],
            'revoked_at' => $row['revoked_at'] !== null ? (string) $row['revoked_at'] : null,
            'revoked_reason' => $row['revoked_reason'] !== null ? (string) $row['revoked_reason'] : null,
            'last_login_at' => $row['last_login_at'] !== null ? (string) $row['last_login_at'] : null,
        ];
    }
}
