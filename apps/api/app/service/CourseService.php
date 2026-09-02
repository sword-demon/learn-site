<?php

declare(strict_types=1);

namespace App\service;

use App\model\Category;
use App\model\Chapter;
use App\model\Course;
use App\model\Lesson;
use App\service\DataScopeService;
use App\support\HtmlSanitizer;
use App\support\Logger;
use support\think\Db;

/**
 * CourseService — the catalog business rules for Phase 4.
 *
 *  - createCourse / updateCourse / publishCourse / unpublishCourse / deleteCourse
 *  - createChapter / updateChapter / deleteChapter
 *  - createLesson  / updateLesson  / deleteLesson
 *  - listForAdmin  / getCourseTree
 *
 * Invariants (all throw BusinessException):
 *   1. category_id exists and status='enabled'.
 *   2. department_id exists and status='enabled'.
 *   3. price_mode='paid' → list_price>0; sale_price in [0, list_price).
 *      When sale_price>0, [sale_start_at, sale_end_at) must be valid and
 *      the current time must lie inside the window (publish-time check).
 *   4. intro_rich_text passes HtmlSanitizer on every write.
 *   5. publishCourse: category enabled, intro non-empty, ≥1 enabled
 *      chapter containing ≥1 enabled lesson whose required payload is set.
 *   6. deleteCourse: actor can access the course, status is draft or
 *      unpublished, and no orders, entitlements, learning records, or
 *      learning-map entries reference it. Detached assets are retained.
 *
 *   ponytail: invariant — orders / enrollments / entitlements / qa /
 *   reviews / course_student hold course_id only; they re-resolve to the
 *   current department via JOIN courses at read time. A
 *   course.department_id change automatically rescopes every derived row
 *   (FR-076). Future migrations MUST NOT add a denormalized department_id
 *   to any of these tables.
 */
final class CourseService
{
    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createCourse(array $input, int $actorStaffAccountId): array
    {
        $this->assertCourseInput($input, isUpdate: false);
        $now = date('Y-m-d H:i:s');
        $intro = HtmlSanitizer::sanitize((string) ($input['intro_rich_text'] ?? ''));

        return Db::transaction(function () use ($input, $intro, $actorStaffAccountId, $now) {
            $id = (int) Course::create([
                'department_id'        => (int) $input['department_id'],
                'category_id'          => (int) $input['category_id'],
                'title'                => (string) $input['title'],
                'cover_url'            => $this->nullIfEmpty($input['cover_url'] ?? null),
                'teacher_name'         => (string) $input['teacher_name'],
                'summary'              => (string) $input['summary'],
                'intro_rich_text'      => $intro['html'],
                'status'               => 'draft',
                'price_mode'           => (string) $input['price_mode'],
                'list_price'           => (float) ($input['list_price'] ?? 0),
                'sale_price'           => (float) ($input['sale_price'] ?? 0),
                'sale_start_at'        => $this->datetimeOrNull($input['sale_start_at'] ?? null),
                'sale_end_at'          => $this->datetimeOrNull($input['sale_end_at'] ?? null),
                'created_by_staff_id'  => $actorStaffAccountId,
                'created_at'           => $now,
                'updated_at'           => $now,
            ])->id;
            Logger::info('course.created', ['course_id' => $id, 'actor_id' => $actorStaffAccountId]);
            return $this->getCourseTree($id);
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateCourse(int $id, array $input, int $actorStaffAccountId): array
    {
        $course = Course::find($id);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $scope = (new DataScopeService())->resolveForCourses($actorStaffAccountId);
        $courseRow = $course->toArray();
        $currentDepartmentId = (int) $courseRow['department_id'];
        DataScopeService::assertCourseAccessibleFromScope(
            $scope,
            $currentDepartmentId,
            (int) $courseRow['created_by_staff_id'],
            $actorStaffAccountId,
        );
        if (
            array_key_exists('department_id', $input)
            && (int) $input['department_id'] !== $currentDepartmentId
        ) {
            DataScopeService::assertWritableDepartmentFromScope($scope, (int) $input['department_id']);
        }
        $merged = array_merge($this->rowToInput($course), $input);
        $this->assertCourseInput($merged, isUpdate: true);

        $introHtml = null;
        if (array_key_exists('intro_rich_text', $input)) {
            $introHtml = HtmlSanitizer::sanitize((string) $input['intro_rich_text'])['html'];
        }

        Db::transaction(function () use ($course, $input, $introHtml) {
            $patch = ['updated_at' => date('Y-m-d H:i:s')];
            $map = [
                'department_id' => 'department_id',
                'category_id'   => 'category_id',
                'title'         => 'title',
                'cover_url'     => 'cover_url',
                'teacher_name'  => 'teacher_name',
                'summary'       => 'summary',
                'price_mode'    => 'price_mode',
                'list_price'    => 'list_price',
                'sale_price'    => 'sale_price',
                'sale_start_at' => 'sale_start_at',
                'sale_end_at'   => 'sale_end_at',
            ];
            foreach ($map as $key => $col) {
                if (!array_key_exists($key, $input)) {
                    continue;
                }
                if ($key === 'cover_url' || $key === 'sale_start_at' || $key === 'sale_end_at') {
                    $patch[$col] = $this->datetimeOrNull($input[$key] ?? null)
                        ?? $this->nullIfEmpty($input[$key] ?? null);
                    if ($patch[$col] === null) {
                        $patch[$col] = null;
                    }
                } else {
                    $patch[$col] = is_string($input[$key] ?? null) ? (string) $input[$key] : (float) $input[$key];
                }
            }
            if ($introHtml !== null) {
                $patch['intro_rich_text'] = $introHtml;
            }
            Course::where('id', $course->id)->update($patch);
        });

        Logger::info('course.updated', ['course_id' => $id, 'actor_id' => $actorStaffAccountId]);
        return $this->getCourseTree($id);
    }

    /** @return array<string, mixed> */
    public function publishCourse(int $id, ?int $actorStaffAccountId = null): array
    {
        $course = Course::find($id);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $status = (string) $course->status;
        if ($status === 'published') {
            // Idempotent re-publish: no new dispatch, no duplicate inbox rows.
            return $this->getCourseTree($id);
        }
        $this->assertPublishable($course);

        Db::transaction(function () use ($course) {
            Course::where('id', $course->id)->update([
                'status'     => 'published',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        Logger::info('course.published', ['course_id' => (int) $course->id]);
        if ($actorStaffAccountId !== null) {
            $this->notifyCoursePublished((int) $course->id, $actorStaffAccountId);
        }
        return $this->getCourseTree((int) $course->id);
    }

    /**
     * Fire the course_published fan-out after the status flip has committed.
     * NotificationDispatchService already tolerates enqueue failures (marks
     * the dispatch `failed` for retry); this guard only keeps an unexpected
     * dispatch-write error from failing the publish API itself (FR-008).
     */
    private function notifyCoursePublished(int $courseId, int $staffId): void
    {
        try {
            $course = Db::name('courses')
                ->where('id', $courseId)
                ->field('id,title,summary')
                ->find();
            if (is_array($course)) {
                (new NotificationDispatchService())->sendCoursePublished($course, $staffId);
            }
        } catch (\Throwable $e) {
            Logger::warning('course.publish.notify_failed', [
                'course_id' => $courseId,
                'err' => $e->getMessage(),
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function unpublishCourse(int $id): array
    {
        $course = Course::find($id);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        if ((string) $course->status !== 'published') {
            return $this->getCourseTree($id);
        }
        Db::transaction(function () use ($course) {
            Course::where('id', $course->id)->update([
                'status'     => 'unpublished',
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        Logger::info('course.unpublished', ['course_id' => (int) $course->id]);
        return $this->getCourseTree((int) $course->id);
    }

    public function deleteCourse(int $id, int $actorStaffAccountId): void
    {
        $course = Course::find($id);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $courseRow = $course->toArray();
        DataScopeService::assertCourseAccessibleFromScope(
            (new DataScopeService())->resolveForCourses($actorStaffAccountId),
            (int) $courseRow['department_id'],
            (int) $courseRow['created_by_staff_id'],
            $actorStaffAccountId,
        );
        if (!$course->isDeletableStatus()) {
            throw new BusinessException('CONFLICT', 'COURSE_DELETE_REQUIRES_UNPUBLISHED');
        }
        if ((int) Db::name('orders')->where('course_id', $id)->count() > 0) {
            throw new BusinessException('CONFLICT', 'COURSE_HAS_ORDERS');
        }
        if ((int) Db::name('course_entitlements')->where('course_id', $id)->count() > 0) {
            throw new BusinessException('CONFLICT', 'COURSE_HAS_ENTITLEMENTS');
        }
        if ($this->hasCourseLearningRecords($id)) {
            throw new BusinessException('CONFLICT', 'COURSE_HAS_LEARNING_RECORDS');
        }
        if ((int) Db::name('map_stage_courses')->where('course_id', $id)->count() > 0) {
            throw new BusinessException('CONFLICT', 'COURSE_IN_LEARNING_MAP');
        }
        Db::transaction(function () use ($id) {
            Course::where('id', $id)->delete();
        });
        Logger::info('course.deleted', ['course_id' => $id]);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createChapter(int $courseId, array $input): array
    {
        $course = Course::find($courseId);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 128) {
            throw new BusinessException('VALIDATION_FAILED', 'CHAPTER_TITLE_INVALID');
        }
        $sort = isset($input['sort']) ? max(0, (int) $input['sort']) : 0;
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::transaction(function () use ($courseId, $title, $sort, $now) {
            return Chapter::create([
                'course_id'  => $courseId,
                'title'      => $title,
                'sort'       => $sort,
                'status'     => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ])->id;
        });
        return $this->chapterRow($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateChapter(int $chapterId, array $input): array
    {
        $chapter = Chapter::find($chapterId);
        if (!$chapter) {
            throw new BusinessException('NOT_FOUND', 'CHAPTER_NOT_FOUND');
        }
        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            if ($title === '' || mb_strlen($title) > 128) {
                throw new BusinessException('VALIDATION_FAILED', 'CHAPTER_TITLE_INVALID');
            }
            $patch['title'] = $title;
        }
        if (array_key_exists('sort', $input)) {
            $patch['sort'] = max(0, (int) $input['sort']);
        }
        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['enabled', 'disabled'], true)) {
                throw new BusinessException('VALIDATION_FAILED', 'CHAPTER_STATUS_INVALID');
            }
            $patch['status'] = $status;
        }
        Db::transaction(function () use ($chapterId, $patch) {
            Chapter::where('id', $chapterId)->update($patch);
        });
        return $this->chapterRow($chapterId);
    }

    public function deleteChapter(int $chapterId): void
    {
        $chapter = Chapter::find($chapterId);
        if (!$chapter) {
            throw new BusinessException('NOT_FOUND', 'CHAPTER_NOT_FOUND');
        }
        $lessonCount = (int) Db::name('lessons')->where('chapter_id', $chapterId)->count();
        if ($lessonCount > 0) {
            throw new BusinessException('CONFLICT', 'CHAPTER_HAS_LESSONS');
        }
        Db::transaction(function () use ($chapterId) {
            Chapter::where('id', $chapterId)->delete();
        });
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createLesson(int $courseId, array $input): array
    {
        $course = Course::find($courseId);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $chapterId = (int) ($input['chapter_id'] ?? 0);
        if ($chapterId <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'LESSON_CHAPTER_REQUIRED');
        }
        $chapter = Chapter::find($chapterId);
        if (!$chapter || (int) $chapter->course_id !== $courseId) {
            throw new BusinessException('NOT_FOUND', 'CHAPTER_NOT_FOUND');
        }
        $row = $this->buildLessonRow($input);
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::transaction(function () use ($chapterId, $row, $now) {
            return Lesson::create(array_merge($row, [
                'chapter_id' => $chapterId,
                'created_at' => $now,
                'updated_at' => $now,
            ]))->id;
        });
        return $this->lessonRow($id);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateLesson(int $lessonId, array $input): array
    {
        $lesson = Lesson::find($lessonId);
        if (!$lesson) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        $patch = $this->buildLessonRow($input, isUpdate: true);
        $patch['updated_at'] = date('Y-m-d H:i:s');
        Db::transaction(function () use ($lessonId, $patch) {
            Lesson::where('id', $lessonId)->update($patch);
        });
        return $this->lessonRow($lessonId);
    }

    public function deleteLesson(int $lessonId): void
    {
        $lesson = Lesson::find($lessonId);
        if (!$lesson) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        Db::transaction(function () use ($lessonId) {
            Lesson::where('id', $lessonId)->delete();
        });
    }

    /**
     * @param array{status?:string,category_id?:int,q?:string,page?:int,limit?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listForAdmin(int $staffAccountId, array $filters = []): array
    {
        // Phase 10: full data-scope (FR-074, FR-076, FR-080).
        $perms = $this->permissionsFor($staffAccountId);
        if (!in_array('*', $perms, true) && !in_array('course.view', $perms, true)) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'limit' => 20];
        }
        $scope = (new DataScopeService())->resolveForCourses($staffAccountId);

        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));
        $q = Db::name('courses');
        // scope=all → no WHERE; scope narrows by department_id IN (...) OR self.
        if (!$scope['all']) {
            if ($scope['department_ids'] === [] && !$scope['include_self']) {
                return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
            }
            $q->where(function ($w) use ($scope, $staffAccountId) {
                if ($scope['department_ids'] !== []) {
                    $w->where('department_id', 'in', $scope['department_ids']);
                }
                if ($scope['include_self']) {
                    if ($scope['department_ids'] !== []) {
                        $w->whereOr('created_by_staff_id', $staffAccountId);
                    } else {
                        $w->where('created_by_staff_id', $staffAccountId);
                    }
                }
            });
        }
        if (!empty($filters['status'])) {
            $q->where('status', (string) $filters['status']);
        }
        if (!empty($filters['category_id'])) {
            $q->where('category_id', (int) $filters['category_id']);
        }
        if (!empty($filters['q'])) {
            $kw = '%' . str_replace(['%', '_'], ['\\%', '\\_'], (string) $filters['q']) . '%';
            $q->where('title', 'like', $kw);
        }
        $total = (clone $q)->count();
        $items = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();
        return [
            'items' => array_map(fn($r) => $this->shapeCourseRow($r), $items),
            'total' => (int) $total,
            'page'  => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string, mixed> */
    public function getCourseTree(int $id): array
    {
        $course = Course::find($id);
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        $chapterRows = Db::name('chapters')->where('course_id', $id)
            ->order('sort', 'asc')->order('id', 'asc')->select()->toArray();
        $chapterIds = array_column($chapterRows, 'id');
        $lessonRows = $chapterIds
            ? Db::name('lessons')->where('chapter_id', 'in', $chapterIds)
                ->order('sort', 'asc')->order('id', 'asc')->select()->toArray()
            : [];
        $lessonsByChapter = [];
        foreach ($lessonRows as $lr) {
            $lessonsByChapter[(int) $lr['chapter_id']][] = $this->shapeLessonRow($lr);
        }
        $chapters = [];
        foreach ($chapterRows as $cr) {
            $chapters[] = [
                'id'         => (int) $cr['id'],
                'course_id'  => (int) $cr['course_id'],
                'title'      => (string) $cr['title'],
                'sort'       => (int) $cr['sort'],
                'status'     => (string) $cr['status'],
                'lessons'    => $lessonsByChapter[(int) $cr['id']] ?? [],
            ];
        }
        $row = $this->shapeCourseRow($course->toArray());
        $row['chapters'] = $chapters;
        return $row;
    }

    // ─── internal helpers ──────────────────────────────────────────────

    /** @param array<string, mixed> $input */
    private function assertCourseInput(array $input, bool $isUpdate): void
    {
        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 128) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_TITLE_INVALID');
        }
        $teacher = trim((string) ($input['teacher_name'] ?? ''));
        if ($teacher === '' || mb_strlen($teacher) > 64) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_TEACHER_INVALID');
        }
        $summary = (string) ($input['summary'] ?? '');
        if (mb_strlen($summary) > 255) {
            throw new BusinessException('VALIDATION_FAILED', 'COURSE_SUMMARY_TOO_LONG');
        }
        $priceMode = (string) ($input['price_mode'] ?? 'free');
        if (!in_array($priceMode, ['free', 'paid'], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'PRICE_MODE_INVALID');
        }
        $listPrice = (float) ($input['list_price'] ?? 0);
        $salePrice = (float) ($input['sale_price'] ?? 0);
        if ($priceMode === 'paid') {
            if ($listPrice <= 0) {
                throw new BusinessException('VALIDATION_FAILED', 'LIST_PRICE_REQUIRED');
            }
            if ($salePrice < 0 || $salePrice >= $listPrice) {
                throw new BusinessException('VALIDATION_FAILED', 'SALE_PRICE_INVALID');
            }
            if ($salePrice > 0) {
                $start = $this->datetimeOrNull($input['sale_start_at'] ?? null);
                $end   = $this->datetimeOrNull($input['sale_end_at']   ?? null);
                if (!$start || !$end || strtotime($end) <= strtotime($start)) {
                    throw new BusinessException('VALIDATION_FAILED', 'SALE_WINDOW_INVALID');
                }
            }
        } else {
            // free courses ignore prices entirely
            $listPrice = 0.0;
            $salePrice = 0.0;
        }

        $deptId = (int) ($input['department_id'] ?? 0);
        if ($deptId > 0) {
            $dept = Db::name('departments')->where('id', $deptId)->find();
            if (!$dept) {
                throw new BusinessException('VALIDATION_FAILED', 'DEPARTMENT_NOT_FOUND');
            }
            if (($dept['status'] ?? null) !== 'enabled') {
                throw new BusinessException('VALIDATION_FAILED', 'DEPARTMENT_DISABLED');
            }
        } elseif (!$isUpdate) {
            throw new BusinessException('VALIDATION_FAILED', 'DEPARTMENT_REQUIRED');
        }

        $catId = (int) ($input['category_id'] ?? 0);
        if ($catId > 0) {
            $cat = Db::name('categories')->where('id', $catId)->find();
            if (!$cat) {
                throw new BusinessException('VALIDATION_FAILED', 'CATEGORY_NOT_FOUND');
            }
            if (($cat['status'] ?? null) !== 'enabled') {
                throw new BusinessException('VALIDATION_FAILED', 'CATEGORY_DISABLED');
            }
        } elseif (!$isUpdate) {
            throw new BusinessException('VALIDATION_FAILED', 'CATEGORY_REQUIRED');
        }

        // Intro is sanitised separately so the caller always gets a clean copy.
        if (!$isUpdate) {
            $intro = HtmlSanitizer::sanitize((string) ($input['intro_rich_text'] ?? ''));
            if ($intro['html'] === '') {
                throw new BusinessException('VALIDATION_FAILED', 'INTRO_REQUIRED');
            }
        }
    }

    private function assertPublishable(Course $course): void
    {
        $category = Db::name('categories')->where('id', (int) $course->category_id)->find();
        if (!$category || ($category['status'] ?? null) !== 'enabled') {
            throw new BusinessException('VALIDATION_FAILED', 'CATEGORY_DISABLED');
        }
        $intro = HtmlSanitizer::sanitize((string) ($course->intro_rich_text ?? ''));
        if ($intro['html'] === '') {
            throw new BusinessException('VALIDATION_FAILED', 'INTRO_REQUIRED');
        }
        // Sale window, if sale_price>0, must contain "now".
        if ((float) $course->sale_price > 0.0) {
            $start = strtotime((string) $course->sale_start_at);
            $end   = strtotime((string) $course->sale_end_at);
            $now   = time();
            if (!$start || !$end || $end <= $start || $now < $start || $now >= $end) {
                throw new BusinessException('VALIDATION_FAILED', 'SALE_WINDOW_EXPIRED');
            }
        }
        $chapters = Db::name('chapters')->where('course_id', (int) $course->id)
            ->where('status', 'enabled')->select()->toArray();
        if (!$chapters) {
            throw new BusinessException('VALIDATION_FAILED', 'NO_PUBLISHABLE_CHAPTER');
        }
        foreach ($chapters as $ch) {
            $lessons = Db::name('lessons')->where('chapter_id', (int) $ch['id'])
                ->where('status', 'enabled')->select()->toArray();
            foreach ($lessons as $ls) {
                if ($this->lessonHasPayload($ls)) {
                    return; // one good lesson is enough
                }
            }
        }
        throw new BusinessException('VALIDATION_FAILED', 'NO_PUBLISHABLE_LESSON');
    }

    /** @param array<string, mixed> $lesson */
    private function lessonHasPayload(array $lesson): bool
    {
        $type = (string) ($lesson['content_type'] ?? '');
        if ($type === 'markdown') {
            return trim((string) ($lesson['body_markdown'] ?? '')) !== '';
        }
        if ($type === 'pdf' || $type === 'video') {
            return (int) ($lesson['asset_id'] ?? 0) > 0;
        }
        return false;
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function buildLessonRow(array $input, bool $isUpdate = false): array
    {
        $patch = [];
        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            if ($title === '' || mb_strlen($title) > 128) {
                throw new BusinessException('VALIDATION_FAILED', 'LESSON_TITLE_INVALID');
            }
            $patch['title'] = $title;
        } elseif (!$isUpdate) {
            throw new BusinessException('VALIDATION_FAILED', 'LESSON_TITLE_REQUIRED');
        }
        if (array_key_exists('sort', $input)) {
            $patch['sort'] = max(0, (int) $input['sort']);
        }
        if (array_key_exists('status', $input)) {
            $status = (string) $input['status'];
            if (!in_array($status, ['enabled', 'disabled'], true)) {
                throw new BusinessException('VALIDATION_FAILED', 'LESSON_STATUS_INVALID');
            }
            $patch['status'] = $status;
        }
        if (array_key_exists('is_preview', $input)) {
            $patch['is_preview'] = $input['is_preview'] ? 1 : 0;
        }
        if (array_key_exists('duration_seconds', $input)) {
            $patch['duration_seconds'] = max(0, (int) $input['duration_seconds']);
        }
        $type = $input['content_type'] ?? null;
        if ($type !== null) {
            if (!in_array($type, ['markdown', 'pdf', 'video'], true)) {
                throw new BusinessException('VALIDATION_FAILED', 'LESSON_TYPE_INVALID');
            }
            $patch['content_type'] = $type;
            if ($type === 'markdown') {
                $patch['body_markdown'] = (string) ($input['body_markdown'] ?? '');
                $patch['asset_id']      = null;
            } else {
                $assetId = (int) ($input['asset_id'] ?? 0);
                if ($assetId <= 0) {
                    throw new BusinessException('VALIDATION_FAILED', 'LESSON_ASSET_REQUIRED');
                }
                $exists = Db::name('assets')->where('id', $assetId)->find();
                if (!$exists) {
                    throw new BusinessException('NOT_FOUND', 'ASSET_NOT_FOUND');
                }
                $patch['asset_id']      = $assetId;
                $patch['body_markdown'] = null;
            }
        }
        return $patch;
    }

    private function hasCourseLearningRecords(int $courseId): bool
    {
        if ((int) Db::name('course_enrollments')->where('course_id', $courseId)->count() > 0) {
            return true;
        }

        return (int) Db::name('lesson_progresses')->alias('lp')
            ->join('lessons l', 'l.id = lp.lesson_id')
            ->join('chapters ch', 'ch.id = l.chapter_id')
            ->where('ch.course_id', $courseId)
            ->count() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeCourseRow(array $row): array
    {
        return [
            'id'                   => (int) $row['id'],
            'department_id'        => (int) $row['department_id'],
            'category_id'          => (int) $row['category_id'],
            'title'                => (string) $row['title'],
            'cover_url'            => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
            'teacher_name'         => (string) $row['teacher_name'],
            'summary'              => (string) $row['summary'],
            'intro_rich_text'      => (string) ($row['intro_rich_text'] ?? ''),
            'status'               => (string) $row['status'],
            'price_mode'           => (string) $row['price_mode'],
            'list_price'           => (float) $row['list_price'],
            'sale_price'           => (float) $row['sale_price'],
            'sale_start_at'        => $row['sale_start_at'] ?? null,
            'sale_end_at'          => $row['sale_end_at'] ?? null,
            'created_by_staff_id'  => (int) $row['created_by_staff_id'],
            'created_at'           => (string) $row['created_at'],
            'updated_at'           => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeLessonRow(array $row): array
    {
        return [
            'id'               => (int) $row['id'],
            'chapter_id'       => (int) $row['chapter_id'],
            'title'            => (string) $row['title'],
            'sort'             => (int) $row['sort'],
            'status'           => (string) $row['status'],
            'content_type'     => (string) $row['content_type'],
            'body_markdown'    => $row['body_markdown'] !== null ? (string) $row['body_markdown'] : null,
            'asset_id'         => $row['asset_id'] !== null ? (int) $row['asset_id'] : null,
            'is_preview'       => (int) ($row['is_preview'] ?? 0) === 1,
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function chapterRow(int $id): array
    {
        $ch = Chapter::find($id);
        if (!$ch) {
            throw new BusinessException('NOT_FOUND', 'CHAPTER_NOT_FOUND');
        }
        return [
            'id'        => (int) $ch->id,
            'course_id' => (int) $ch->course_id,
            'title'     => (string) $ch->title,
            'sort'      => (int) $ch->sort,
            'status'    => (string) $ch->status,
        ];
    }

    /** @return array<string, mixed> */
    private function lessonRow(int $id): array
    {
        $ls = Lesson::find($id);
        if (!$ls) {
            throw new BusinessException('NOT_FOUND', 'LESSON_NOT_FOUND');
        }
        return $this->shapeLessonRow($ls->toArray());
    }

    /** @return array<string, mixed> */
    private function rowToInput(Course $course): array
    {
        return [
            'department_id' => (int) $course->department_id,
            'category_id'   => (int) $course->category_id,
            'title'         => (string) $course->title,
            'cover_url'     => $course->cover_url,
            'teacher_name'  => (string) $course->teacher_name,
            'summary'       => (string) $course->summary,
            'intro_rich_text' => (string) ($course->intro_rich_text ?? ''),
            'price_mode'    => (string) $course->price_mode,
            'list_price'    => (float) $course->list_price,
            'sale_price'    => (float) $course->sale_price,
            'sale_start_at' => $course->sale_start_at,
            'sale_end_at'   => $course->sale_end_at,
        ];
    }

    /** @return list<string> */
    private function permissionsFor(int $staffAccountId): array
    {
        try {
            return (new PermissionService())->effectiveCodes($staffAccountId);
        } catch (\Throwable) {
            return [];
        }
    }

    private function nullIfEmpty(mixed $v): ?string
    {
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        return $s === '' ? null : $s;
    }

    private function datetimeOrNull(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        $s = (string) $v;
        $ts = strtotime($s);
        return $ts === false ? null : date('Y-m-d H:i:s', $ts);
    }
}
