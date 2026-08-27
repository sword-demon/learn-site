<?php
declare(strict_types=1);

namespace App\service;

use App\model\Category;
use App\model\Course;
use support\think\Db;

/**
 * PublicCatalogService — read-only catalog access for the learner surface.
 *
 * Phase 5 (US1). All queries assume `status='published'` only and never
 * surface draft / unpublished rows or admin-only fields (created_by_staff_id,
 * sale window internally). The service is intentionally narrow — list
 * courses under a category, return course detail with chapter/lesson
 * summaries that already apply the preview / locked visibility rule from
 * FR-029 (and the FR-013 first-preview rule baked into the row shape).
 *
 * // ponytail: viewer_authorized delegates to EntitlementService.
 * Both this and PublicLessonService share the same check so lesson
 * gating and the catalog "locked" rendering can never disagree.
 */
final class PublicCatalogService
{
    public function __construct(private readonly EntitlementService $entitlements)
    {
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function categoryBreadcrumb(int $categoryId): array
    {
        try {
            $row = Db::name('categories')->where('id', $categoryId)->find();
        } catch (\Throwable) {
            $row = null;
        }
        if (!$row || ($row['status'] ?? null) !== 'enabled') {
            return [];
        }
        return [
            'id'   => (int) $row['id'],
            'name' => (string) $row['name'],
            'path' => (string) ($row['path'] ?? '/'),
            'depth' => (int) ($row['depth'] ?? 1),
        ];
    }

    /**
     * Latest published courses for the learner home shelf (newest first).
     *
     * @return list<array<string,mixed>>
     */
    public function recentPublishedCourses(int $limit = 12): array
    {
        $limit = min(24, max(1, $limit));
        try {
            $rows = Db::name('courses')
                ->where('status', 'published')
                ->order('updated_at', 'desc')
                ->order('id', 'desc')
                ->limit($limit)
                ->select()
                ->toArray();
        } catch (\Throwable) {
            return [];
        }
        if (!is_array($rows)) {
            return [];
        }
        return array_map(
            fn($r) => $this->shapeListItem($r),
            $rows,
        );
    }

    /**
     * @param array{page?:int,limit?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function coursesByCategory(int $categoryId, ?int $viewerAccountId, array $filters = []): array
    {
        $page  = max(1, (int) ($filters['page']  ?? 1));
        $limit = min(100, max(1, (int) ($filters['limit'] ?? 20)));

        $descendantIds = $this->collectEnabledDescendantIds($categoryId);
        if (empty($descendantIds)) {
            // category missing or disabled → empty page, not 404
            return ['items' => [], 'total' => 0, 'page' => $page, 'limit' => $limit];
        }

        $q = Db::name('courses')
            ->where('category_id', 'in', $descendantIds)
            ->where('status', 'published');
        $total = (clone $q)->count();
        $rows  = $q->order('id', 'desc')->page($page, $limit)->select()->toArray();

        $items = array_map(
            fn($r) => $this->shapeListItem($r),
            $rows,
        );
        return ['items' => $items, 'total' => (int) $total, 'page' => $page, 'limit' => $limit];
    }

    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    /** @return array<string, mixed> */
    public function courseDetail(int $courseId, ?int $viewerAccountId): array
    {
        try {
            $course = Course::where('id', '=', $courseId)
                ->where('status', '=', 'published')
                ->find();
        } catch (\Throwable) {
            $course = null;
        }
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }

        $category = Db::name('categories')->where('id', (int) $course->category_id)->find();
        $authorized = $this->viewerAuthorized((int) $course->id, $viewerAccountId); // ponytail: phase-6

        $chapterRows = Db::name('chapters')
            ->where('course_id', $courseId)
            ->where('status', 'enabled')
            ->order('sort', 'asc')->order('id', 'asc')
            ->select()->toArray();

        $chapterIds = array_column($chapterRows, 'id');
        $lessonRows = $chapterIds
            ? Db::name('lessons')->where('chapter_id', 'in', $chapterIds)
                ->where('status', 'enabled')
                ->order('sort', 'asc')->order('id', 'asc')
                ->select()->toArray()
            : [];

        $lessonsByChapter = [];
        foreach ($lessonRows as $lr) {
            $lessonsByChapter[(int) $lr['chapter_id']][] = $this->shapeLessonSummary($lr, $authorized);
        }
        $chapters = [];
        foreach ($chapterRows as $cr) {
            $chapters[] = [
                'id'         => (int) $cr['id'],
                'course_id'  => (int) $cr['course_id'],
                'title'      => (string) $cr['title'],
                'sort'       => (int) $cr['sort'],
                'lessons'    => $lessonsByChapter[(int) $cr['id']] ?? [],
            ];
        }

        return [
            'course' => $this->shapeDetailCourse($course->toArray(), $category, $authorized),
            'chapters' => $chapters,
        ];
    }

    // ─── helpers ──────────────────────────────────────────────────────

    /**
     * Collect the requested category and any descendants (depth ≤ 3).
     * Excludes disabled categories — a disabled parent hides its tree.
     *
     * @return list<int>
     */
    private function collectEnabledDescendantIds(int $rootId): array
    {
        $root = Db::name('categories')->where('id', $rootId)->find();
        if (!$root || ($root['status'] ?? null) !== 'enabled') {
            return [];
        }
        $ids = [(int) $root['id']];
        $current = [(int) $root['id']];
        for ($depth = 1; $depth <= 3; $depth++) {
            if (empty($current)) {
                break;
            }
            $children = Db::name('categories')
                ->where('parent_id', 'in', $current)
                ->where('status', 'enabled')
                ->select()->toArray();
            $current = [];
            foreach ($children as $c) {
                $id = (int) $c['id'];
                if (!in_array($id, $ids, true)) {
                    $ids[] = $id;
                    $current[] = $id;
                }
            }
        }
        return $ids;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeListItem(array $row): array
    {
        $previewAvailable = $this->hasPreviewLesson((int) $row['id']);
        $learnerCount = $this->publicLearnerCount((int) $row['id']);
        return [
            'id'              => (int) $row['id'],
            'category_id'     => (int) $row['category_id'],
            'title'           => (string) $row['title'],
            'cover_url'       => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
            'teacher_name'    => (string) $row['teacher_name'],
            'summary'         => (string) ($row['summary'] ?? ''),
            'price_mode'      => (string) $row['price_mode'],
            'list_price'      => (float) ($row['list_price'] ?? 0),
            'sale_price'      => (float) ($row['sale_price'] ?? 0),
            'sale_start_at'   => $row['sale_start_at'] ?? null,
            'sale_end_at'     => $row['sale_end_at'] ?? null,
            'preview_available' => $previewAvailable,
            'learner_count'   => $learnerCount,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $category
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $category
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed>|null $category
     * @return array<string, mixed>
     */
    private function shapeDetailCourse(array $row, ?array $category, bool $authorized): array
    {
        return [
            'id'              => (int) $row['id'],
            'category_id'     => (int) $row['category_id'],
            'category_name'   => $category ? (string) $category['name'] : '',
            'title'           => (string) $row['title'],
            'cover_url'       => $row['cover_url'] !== null ? (string) $row['cover_url'] : null,
            'teacher_name'    => (string) $row['teacher_name'],
            'summary'         => (string) ($row['summary'] ?? ''),
            'intro_html'      => (string) ($row['intro_rich_text'] ?? ''),
            'price_mode'      => (string) $row['price_mode'],
            'list_price'      => (float) ($row['list_price'] ?? 0),
            'sale_price'      => (float) ($row['sale_price'] ?? 0),
            'sale_start_at'   => $row['sale_start_at'] ?? null,
            'sale_end_at'     => $row['sale_end_at'] ?? null,
            'viewer_authorized' => $authorized,
            'learner_count'   => $this->publicLearnerCount((int) $row['id']),
            'created_at'      => (string) ($row['created_at'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeLessonSummary(array $row, bool $authorized): array
    {
        $isPreview = (int) ($row['is_preview'] ?? 0) === 1;
        $locked = !$isPreview && !$authorized;
        return [
            'id'               => (int) $row['id'],
            'title'            => (string) $row['title'],
            'sort'             => (int) ($row['sort'] ?? 0),
            'content_type'     => (string) $row['content_type'],
            'duration_seconds' => (int) ($row['duration_seconds'] ?? 0),
            'is_preview'       => $isPreview,
            'locked'           => $locked,
        ];
    }

    private function hasPreviewLesson(int $courseId): bool
    {
        try {
            $n = (int) Db::name('lessons')
                ->where('chapter_id', 'in', function ($q) use ($courseId) {
                    $q->name('chapters')->where('course_id', $courseId)->field('id');
                })
                ->where('status', 'enabled')
                ->where('is_preview', 1)
                ->count();
        } catch (\Throwable) {
            $n = 0;
        }
        return $n > 0;
    }

    private function publicLearnerCount(int $courseId): int
    {
        try {
            return (int) Db::name('course_enrollments')->where('course_id', $courseId)->count();
        } catch (\Throwable) {
            // ponytail: defensive — table is created in Phase 6; if a
            // future migration drops it we'd rather show 0 than 500.
            return 0;
        }
    }

    private function viewerAuthorized(int $courseId, ?int $viewerAccountId): bool
    {
        if ($viewerAccountId === null || $viewerAccountId <= 0) {
            return false;
        }
        // Authoritative check: EntitlementService::viewerAuthorized
        // looks up the active row in course_entitlements. This is the
        // single source of truth shared with PublicLessonService.
        return $this->entitlements->viewerAuthorized($courseId, $viewerAccountId);
    }
}
