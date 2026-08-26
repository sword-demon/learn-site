<?php

declare(strict_types=1);

namespace App\service;

use App\support\Logger;
use support\think\Db;

/**
 * LearningMapService — phase 13 (US6).
 *
 * Two scopes:
 *   - admin surface: CRUD over learning_maps / map_stages /
 *     map_stage_courses; publish-time validation; respects data-scope
 *     via DataScopeService.
 *   - learner surface: list published maps (no draft leakage), detail
 *     with stages + courses, self-enroll and read progress.
 *
 * Rules (data-model §学习地图):
 *   - department_id required, status=draft|published
 *   - stages ordered (sort_order)
 *   - unique (map_id, course_id) across every stage in a map
 *   - publish gate: ≥1 stage, every stage has ≥1 course, every course
 *     status='published'.
 *   - progress = completed current published courses / current published
 *     courses; course_enrollments.completed_at is the completion source.
 *
 * @phpstan-type MapData array<string, mixed>
 */
final class LearningMapService
{
    public function __construct(
        private readonly DataScopeService $scope,
        private readonly EntitlementService $entitlements,
    ) {
    }

    // ─── admin ────────────────────────────────────────────────────────

    /**
     * @param MapData $filters
     * @return MapData
     */
    public function adminListMaps(int $staffId, array $filters, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        if (!$this->hasPermission($staffId, 'map.view')) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }
        $q = Db::name('learning_maps');
        if (!empty($filters['department_id'])) {
            $q->where('department_id', (int) $filters['department_id']);
        }
        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        $scope = $this->scope->resolveForCourses($staffId);
        if (!$scope['all']) {
            if ($scope['department_ids'] === [] && !$scope['include_self']) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
            $q->where(function ($where) use ($scope, $staffId) {
                if ($scope['department_ids'] !== []) {
                    $where->where('department_id', 'in', $scope['department_ids']);
                }
                if ($scope['include_self']) {
                    if ($scope['department_ids'] !== []) {
                        $where->whereOr('created_by_staff_id', $staffId);
                    } else {
                        $where->where('created_by_staff_id', $staffId);
                    }
                }
            });
        }
        $total = (clone $q)->count();
        $rows = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $items = array_map(fn($r) => $this->shapeMapSummary($r), $rows);
        return [
            'items' => $items,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @param MapData $input
     * @return MapData
     */
    public function adminCreateMap(int $staffId, array $input): array
    {
        $this->assertMapInput($input, isUpdate: false);
        if (!$this->hasPermission($staffId, 'map.manage')) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }
        $this->scope->assertWritableDepartment($staffId, (int) $input['department_id']);
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('learning_maps')->insertGetId([
            'department_id'      => (int) $input['department_id'],
            'title'              => trim((string) $input['title']),
            'summary'            => isset($input['summary']) ? trim((string) $input['summary']) : null,
            'cover_url'          => $this->nullableTrimmedString($input['cover_url'] ?? null),
            'objective'          => $this->nullableTrimmedString($input['objective'] ?? null),
            'audience'           => $this->nullableTrimmedString($input['audience'] ?? null),
            'status'             => 'draft',
            'created_by_staff_id' => $staffId,
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);
        Logger::info('map.created', ['map_id' => $id, 'staff_id' => $staffId]);
        return $this->adminGetMap($staffId, $id);
    }

    /**
     * @param MapData $input
     * @return MapData
     */
    public function adminUpdateMap(int $staffId, int $mapId, array $input): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if (isset($input['title'])) {
            $title = trim((string) $input['title']);
            if ($title === '' || mb_strlen($title) > 128) {
                throw new BusinessException('VALIDATION_FAILED', 'MAP_TITLE_INVALID');
            }
            $map['title'] = $title;
        }
        if (array_key_exists('summary', $input)) {
            $map['summary'] = $input['summary'] !== null ? trim((string) $input['summary']) : null;
        }
        if (
            isset($input['department_id'])
            && (int) $input['department_id'] !== (int) $map['department_id']
        ) {
            $this->scope->assertWritableDepartment($staffId, (int) $input['department_id']);
            $map['department_id'] = (int) $input['department_id'];
        }
        foreach (['cover_url', 'objective', 'audience'] as $field) {
            if (array_key_exists($field, $input)) {
                $map[$field] = $this->nullableTrimmedString($input[$field]);
            }
        }
        $now = date('Y-m-d H:i:s');
        Db::name('learning_maps')->where('id', $mapId)->update([
            'title'         => $map['title'],
            'summary'       => $map['summary'],
            'cover_url'     => $map['cover_url'],
            'objective'     => $map['objective'],
            'audience'      => $map['audience'],
            'department_id' => $map['department_id'],
            'updated_at'    => $now,
        ]);
        return $this->adminGetMap($staffId, $mapId);
    }

    /** @return MapData */
    public function adminGetMap(int $staffId, int $mapId): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.view');
        $detail = $this->shapeMapWithStages($map);
        $detail['publish_issues'] = $this->collectPublishIssues($mapId);
        return $detail;
    }

    /**
     * @param MapData $input
     * @return MapData
     */
    public function adminAddStage(int $staffId, int $mapId, array $input): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] !== 'draft') {
            throw new BusinessException('CONFLICT', 'MAP_ALREADY_PUBLISHED');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 128) {
            throw new BusinessException('VALIDATION_FAILED', 'STAGE_TITLE_INVALID');
        }
        $summary = isset($input['summary']) ? trim((string) $input['summary']) : null;
        $nextSort = (int) Db::name('map_stages')
            ->where('map_id', $mapId)
            ->max('sort_order') + 1;
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('map_stages')->insertGetId([
            'map_id'     => $mapId,
            'title'      => $title,
            'summary'    => $summary,
            'sort_order' => $nextSort,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $this->shapeStage(Db::name('map_stages')->where('id', $id)->find());
    }

    /**
     * @param MapData $input
     * @return MapData
     */
    public function adminUpdateStage(int $staffId, int $mapId, int $stageId, array $input): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] !== 'draft') {
            throw new BusinessException('CONFLICT', 'MAP_ALREADY_PUBLISHED');
        }
        $stage = Db::name('map_stages')->where('id', $stageId)->where('map_id', $mapId)->find();
        if (!$stage) {
            throw new BusinessException('NOT_FOUND', 'STAGE_NOT_FOUND');
        }
        if (isset($input['title'])) {
            $title = trim((string) $input['title']);
            if ($title === '' || mb_strlen($title) > 128) {
                throw new BusinessException('VALIDATION_FAILED', 'STAGE_TITLE_INVALID');
            }
            $stage['title'] = $title;
        }
        if (array_key_exists('summary', $input)) {
            $stage['summary'] = $input['summary'] !== null ? trim((string) $input['summary']) : null;
        }
        if (isset($input['sort_order'])) {
            $stage['sort_order'] = max(1, (int) $input['sort_order']);
        }
        $now = date('Y-m-d H:i:s');
        Db::name('map_stages')->where('id', $stageId)->update([
            'title'      => $stage['title'],
            'summary'    => $stage['summary'],
            'sort_order' => $stage['sort_order'],
            'updated_at' => $now,
        ]);
        return $this->shapeStage(Db::name('map_stages')->where('id', $stageId)->find());
    }

    public function adminDeleteStage(int $staffId, int $mapId, int $stageId): void
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] !== 'draft') {
            throw new BusinessException('CONFLICT', 'MAP_ALREADY_PUBLISHED');
        }
        Db::name('map_stages')->where('id', $stageId)->where('map_id', $mapId)->delete();
    }

    /** @return MapData */
    public function adminAddCourseToStage(
        int $staffId,
        int $mapId,
        int $stageId,
        int $courseId,
    ): array {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] !== 'draft') {
            throw new BusinessException('CONFLICT', 'MAP_ALREADY_PUBLISHED');
        }
        $stage = Db::name('map_stages')->where('id', $stageId)->where('map_id', $mapId)->find();
        if (!$stage) {
            throw new BusinessException('NOT_FOUND', 'STAGE_NOT_FOUND');
        }
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $dup = Db::name('map_stage_courses')
            ->where('stage_id', $stageId)
            ->where('course_id', $courseId)
            ->find();
        if ($dup) {
            throw new BusinessException('CONFLICT', 'COURSE_ALREADY_IN_MAP');
        }
        // Reject duplicate course across the same map (data-model rule).
        $dupMap = Db::name('map_stage_courses')->alias('msc')
            ->join('map_stages s', 'msc.stage_id = s.id')
            ->where('s.map_id', $mapId)
            ->where('msc.course_id', $courseId)
            ->find();
        if ($dupMap) {
            throw new BusinessException('CONFLICT', 'COURSE_ALREADY_IN_MAP');
        }
        $nextSort = (int) Db::name('map_stage_courses')
            ->where('stage_id', $stageId)
            ->max('sort_order') + 1;
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('map_stage_courses')->insertGetId([
            'stage_id'    => $stageId,
            'map_id'      => $mapId,
            'course_id'   => $courseId,
            'sort_order'  => $nextSort,
            'created_at'  => $now,
        ]);
        return [
            'id'         => $id,
            'stage_id'   => $stageId,
            'course_id'  => $courseId,
            'sort_order' => $nextSort,
        ];
    }

    public function adminRemoveCourseFromStage(
        int $staffId,
        int $mapId,
        int $stageId,
        int $courseId,
    ): void {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] !== 'draft') {
            throw new BusinessException('CONFLICT', 'MAP_ALREADY_PUBLISHED');
        }
        Db::name('map_stage_courses')
            ->where('stage_id', $stageId)
            ->where('course_id', $courseId)
            ->delete();
    }

    /** @return MapData */
    public function adminPublishMap(int $staffId, int $mapId): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.publish');
        if ((string) $map['status'] === 'published') {
            return $this->adminGetMap($staffId, $mapId);
        }
        $issues = $this->collectPublishIssues($mapId);
        if ($issues !== []) {
            throw new BusinessException('VALIDATION_FAILED', (string) $issues[0]['code']);
        }
        $now = date('Y-m-d H:i:s');
        Db::name('learning_maps')->where('id', $mapId)->update([
            'status'     => 'published',
            'updated_at' => $now,
        ]);
        Logger::info('map.published', ['map_id' => $mapId, 'staff_id' => $staffId]);
        return $this->adminGetMap($staffId, $mapId);
    }

    /** @return MapData */
    public function adminUnpublishMap(int $staffId, int $mapId): array
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.publish');
        $now = date('Y-m-d H:i:s');
        Db::name('learning_maps')->where('id', $mapId)->update([
            'status'     => 'draft',
            'updated_at' => $now,
        ]);
        return $this->adminGetMap($staffId, $mapId);
    }

    public function adminDeleteMap(int $staffId, int $mapId): void
    {
        $map = $this->loadMap($mapId);
        if (!$map) {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $this->assertMapScope($staffId, $map, 'map.manage');
        if ((string) $map['status'] === 'published') {
            throw new BusinessException('CONFLICT', 'PUBLISHED_MAP_NOT_DELETABLE');
        }
        Db::name('learning_maps')->where('id', $mapId)->delete();
    }

    // ─── learner ──────────────────────────────────────────────────────

    /** @return MapData */
    public function learnerListMaps(?int $learnerId, int $page = 1, int $limit = 20): array
    {
        $page = max(1, $page);
        $limit = max(1, min(100, $limit));
        $q = Db::name('learning_maps')->where('status', 'published');
        $total = (clone $q)->count();
        $rows = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        $ids = array_column($rows, 'id');
        if ($learnerId !== null && $learnerId > 0) {
            $enrolledMapIds = $ids
                ? Db::name('map_enrollments')
                    ->where('learner_id', $learnerId)
                    ->where('map_id', 'in', $ids)
                    ->column('map_id')
                : [];
            foreach ($enrolledMapIds as $enrolledMapId) {
                $this->recomputeProgress($learnerId, (int) $enrolledMapId);
            }
        }
        $enrollments = $learnerId !== null && $learnerId > 0 && $ids
            ? Db::name('map_enrollments')
                ->where('learner_id', $learnerId)
                ->where('map_id', 'in', $ids)
                ->select()
                ->toArray()
            : [];
        $byId = [];
        foreach ($enrollments as $e) {
            $byId[(int) $e['map_id']] = $e;
        }
        $items = array_map(function ($r) use ($byId) {
            $summary = $this->shapeMapSummary($r);
            $e = $byId[(int) $r['id']] ?? null;
            $summary['enrollment'] = $e ? [
                'enrolled_at'       => (string) $e['enrolled_at'],
                'completed_courses' => (int) $e['completed_courses'],
                'total_courses'     => (int) $e['total_courses'],
                'progress_percent'  => (int) $e['progress_percent'],
                'completed_at'      => $e['completed_at'] ?? null,
            ] : null;
            return $summary;
        }, $rows);
        return [
            'items' => $items,
            'total' => (int) $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return MapData */
    public function learnerGetMap(?int $learnerId, int $mapId): array
    {
        $map = $this->loadMap($mapId);
        if (!$map || (string) $map['status'] !== 'published') {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $enrollment = null;
        if ($learnerId !== null && $learnerId > 0) {
            $enrollment = Db::name('map_enrollments')
                ->where('map_id', $mapId)
                ->where('learner_id', $learnerId)
                ->find();
            if ($enrollment) {
                $this->recomputeProgress($learnerId, $mapId);
                $enrollment = Db::name('map_enrollments')
                    ->where('map_id', $mapId)
                    ->where('learner_id', $learnerId)
                    ->find();
            }
        }
        $detailed = $this->shapeMapWithStages($map, $learnerId);
        $detailed['enrollment'] = $enrollment ? [
            'enrolled_at'       => (string) $enrollment['enrolled_at'],
            'completed_courses' => (int) $enrollment['completed_courses'],
            'total_courses'     => (int) $enrollment['total_courses'],
            'progress_percent'  => (int) $enrollment['progress_percent'],
            'completed_at'      => $enrollment['completed_at'] ?? null,
        ] : null;
        $detailed['next_step'] = $this->nextStep($detailed['stages']);
        return $detailed;
    }

    /** @return MapData */
    public function learnerStart(int $learnerId, int $mapId): array
    {
        if ($learnerId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        $map = $this->loadMap($mapId);
        if (!$map || (string) $map['status'] !== 'published') {
            throw new BusinessException('NOT_FOUND', 'MAP_NOT_FOUND');
        }
        $existing = Db::name('map_enrollments')
            ->where('map_id', $mapId)
            ->where('learner_id', $learnerId)
            ->find();
        if ($existing) {
            return $this->learnerGetMap($learnerId, $mapId);
        }
        $total = (int) Db::name('map_stage_courses')->alias('msc')
            ->join('courses c', 'msc.course_id = c.id')
            ->where('msc.map_id', $mapId)
            ->where('c.status', 'published')
            ->count();
        $now = date('Y-m-d H:i:s');
        try {
            Db::name('map_enrollments')->insert([
                'map_id'             => $mapId,
                'learner_id'         => $learnerId,
                'enrolled_at'        => $now,
                'completed_courses'  => 0,
                'total_courses'      => $total,
                'progress_percent'   => 0,
                'completed_at'       => null,
            ]);
            Logger::info('map.started', ['map_id' => $mapId, 'learner_id' => $learnerId]);
        } catch (\Throwable $exception) {
            $concurrent = Db::name('map_enrollments')
                ->where('map_id', $mapId)
                ->where('learner_id', $learnerId)
                ->find();
            if (!$concurrent) {
                throw $exception;
            }
        }
        return $this->learnerGetMap($learnerId, $mapId);
    }

    /** @return MapData */
    public function recomputeProgress(int $learnerId, int $mapId): array
    {
        $courseIds = Db::name('map_stage_courses')->alias('msc')
            ->join('courses c', 'msc.course_id = c.id')
            ->where('msc.map_id', $mapId)
            ->where('c.status', 'published')
            ->column('msc.course_id');
        $total = count($courseIds);
        $existing = Db::name('map_enrollments')
            ->where('map_id', $mapId)
            ->where('learner_id', $learnerId)
            ->find();
        if ($total === 0) {
            $row = ['completed_courses' => 0, 'total_courses' => 0, 'progress_percent' => 0, 'completed_at' => null];
        } else {
            $completed = (int) Db::name('course_enrollments')
                ->where('learner_id', $learnerId)
                ->where('course_id', 'in', $courseIds)
                ->whereNotNull('completed_at')
                ->count();
            $percent = (int) floor($completed * 100 / $total);
            $row = [
                'completed_courses' => $completed,
                'total_courses'     => $total,
                'progress_percent'  => $percent,
                'completed_at'      => $completed === $total
                    ? ($existing['completed_at'] ?? date('Y-m-d H:i:s'))
                    : null,
            ];
        }
        if ($existing) {
            Db::name('map_enrollments')
                ->where('map_id', $mapId)
                ->where('learner_id', $learnerId)
                ->update($row);
        }
        return $row;
    }

    // ─── helpers ─────────────────────────────────────────────────────

    /** @return MapData|null */
    private function loadMap(int $id): ?array
    {
        $row = Db::name('learning_maps')->where('id', $id)->find();
        return $row ?: null;
    }

    /** @param MapData $map */
    private function assertMapScope(int $staffId, array $map, string $code): void
    {
        if (!$this->hasPermission($staffId, $code)) {
            throw new BusinessException('FORBIDDEN', 'FORBIDDEN');
        }
        DataScopeService::assertCourseAccessibleFromScope(
            $this->scope->resolveForCourses($staffId),
            (int) $map['department_id'],
            (int) $map['created_by_staff_id'],
            $staffId,
        );
    }

    private function hasPermission(int $staffId, string $code): bool
    {
        $codes = (new PermissionService())->effectiveCodes($staffId);
        return in_array('*', $codes, true) || in_array($code, $codes, true);
    }

    private function nullableTrimmedString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    /** @return list<array{code:string,stage_id:?int,course_id:?int}> */
    private function collectPublishIssues(int $mapId): array
    {
        $stages = Db::name('map_stages')
            ->where('map_id', $mapId)
            ->order('sort_order')
            ->select()
            ->toArray();
        if ($stages === []) {
            return [['code' => 'MAP_HAS_NO_STAGES', 'stage_id' => null, 'course_id' => null]];
        }

        $issues = [];
        foreach ($stages as $stage) {
            $steps = Db::name('map_stage_courses')
                ->where('map_id', $mapId)
                ->where('stage_id', $stage['id'])
                ->order('sort_order')
                ->select()
                ->toArray();
            if ($steps === []) {
                $issues[] = [
                    'code' => 'STAGE_HAS_NO_COURSES',
                    'stage_id' => (int) $stage['id'],
                    'course_id' => null,
                ];
                continue;
            }
            foreach ($steps as $step) {
                $course = Db::name('courses')->where('id', $step['course_id'])->find();
                if (!$course || (string) $course['status'] !== 'published') {
                    $issues[] = [
                        'code' => 'MAP_HAS_UNPUBLISHED_COURSE',
                        'stage_id' => (int) $stage['id'],
                        'course_id' => (int) $step['course_id'],
                    ];
                }
            }
        }
        return $issues;
    }

    /**
     * @param list<array<string, mixed>> $stages
     * @return array{map_stage_course_id:int,stage_id:int,course_id:int}|null
     */
    private function nextStep(array $stages): ?array
    {
        foreach ($stages as $stage) {
            foreach ($stage['courses'] as $step) {
                if ($step['available'] && !$step['completed']) {
                    return [
                        'map_stage_course_id' => (int) $step['map_stage_course_id'],
                        'stage_id' => (int) $stage['id'],
                        'course_id' => (int) $step['course_id'],
                    ];
                }
            }
        }
        return null;
    }

    /** @param MapData $input */
    private function assertMapInput(array $input, bool $isUpdate): void
    {
        if (!$isUpdate) {
            foreach (['department_id', 'title'] as $required) {
                if (!isset($input[$required]) || $input[$required] === '') {
                    throw new BusinessException('VALIDATION_FAILED', $required . '_REQUIRED');
                }
            }
        }
        if (isset($input['title'])) {
            $t = trim((string) $input['title']);
            if ($t === '' || mb_strlen($t) > 128) {
                throw new BusinessException('VALIDATION_FAILED', 'MAP_TITLE_INVALID');
            }
        }
        if (isset($input['department_id'])) {
            $dep = Db::name('departments')->where('id', (int) $input['department_id'])->find();
            if (!$dep || (string) $dep['status'] !== 'enabled') {
                throw new BusinessException('VALIDATION_FAILED', 'DEPARTMENT_INVALID');
            }
        }
    }

    /**
     * @param MapData $row
     * @return MapData
     */
    private function shapeMapSummary(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'department_id' => (int) $row['department_id'],
            'title'         => (string) $row['title'],
            'summary'       => $row['summary'] !== null ? (string) $row['summary'] : null,
            'cover_url'     => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
            'objective'     => $row['objective'] !== null ? (string) $row['objective'] : null,
            'audience'      => $row['audience'] !== null ? (string) $row['audience'] : null,
            'status'        => (string) $row['status'],
            'created_at'    => (string) $row['created_at'],
            'updated_at'    => (string) $row['updated_at'],
        ];
    }

    /**
     * @param MapData $map
     * @return MapData
     */
    private function shapeMapWithStages(array $map, ?int $learnerId = null): array
    {
        $stages = Db::name('map_stages')
            ->where('map_id', $map['id'])
            ->order('sort_order')
            ->select()
            ->toArray();
        $stageIds = array_column($stages, 'id');
        $stageCourses = $stageIds ? Db::name('map_stage_courses')
            ->where('stage_id', 'in', $stageIds)
            ->order('sort_order')
            ->select()
            ->toArray() : [];
        $byStage = [];
        $courseIds = [];
        foreach ($stageCourses as $sc) {
            $byStage[(int) $sc['stage_id']][] = $sc;
            $courseIds[] = (int) $sc['course_id'];
        }
        $coursesById = [];
        if ($courseIds) {
            $rows = Db::name('courses')
                ->where('id', 'in', array_values(array_unique($courseIds)))
                ->select()
                ->toArray();
            foreach ($rows as $r) {
                $coursesById[(int) $r['id']] = $r;
            }
        }
        $completedByCourse = [];
        if ($learnerId !== null && $learnerId > 0 && $courseIds !== []) {
            $completedIds = Db::name('course_enrollments')
                ->where('learner_id', $learnerId)
                ->where('course_id', 'in', array_values(array_unique($courseIds)))
                ->whereNotNull('completed_at')
                ->column('course_id');
            $completedByCourse = array_fill_keys(array_map('intval', $completedIds), true);
        }
        $stageShaped = [];
        foreach ($stages as $s) {
            $courseItems = [];
            foreach ($byStage[(int) $s['id']] ?? [] as $sc) {
                $c = $coursesById[(int) $sc['course_id']] ?? null;
                $courseId = (int) $sc['course_id'];
                $courseItems[] = [
                    'map_stage_course_id' => (int) $sc['id'],
                    'course_id'           => $courseId,
                    'sort_order'          => (int) $sc['sort_order'],
                    'available'           => $c !== null && (string) $c['status'] === 'published',
                    'viewer_authorized'   => $this->entitlements->viewerAuthorized($courseId, $learnerId),
                    'completed'           => isset($completedByCourse[$courseId]),
                    'course'              => $c ? [
                        'id'        => (int) $c['id'],
                        'title'     => (string) $c['title'],
                        'teacher_name' => (string) $c['teacher_name'],
                        'cover_url' => $c['cover_url'] !== null ? (string) $c['cover_url'] : null,
                        'status'    => (string) $c['status'],
                    ] : null,
                ];
            }
            $stageShaped[] = [
                'id'         => (int) $s['id'],
                'map_id'     => (int) $s['map_id'],
                'title'      => (string) $s['title'],
                'summary'    => $s['summary'] !== null ? (string) $s['summary'] : null,
                'sort_order' => (int) $s['sort_order'],
                'courses'    => $courseItems,
            ];
        }
        $out = $this->shapeMapSummary($map);
        $out['stages'] = $stageShaped;
        return $out;
    }

    /**
     * @param MapData $row
     * @return MapData
     */
    private function shapeStage(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'map_id'     => (int) $row['map_id'],
            'title'      => (string) $row['title'],
            'summary'    => $row['summary'] !== null ? (string) $row['summary'] : null,
            'sort_order' => (int) $row['sort_order'],
        ];
    }
}
