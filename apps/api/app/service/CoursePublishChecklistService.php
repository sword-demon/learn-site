<?php

declare(strict_types=1);

namespace App\service;

use App\support\HtmlSanitizer;
use App\support\ShanghaiTime;
use support\think\Db;

final class CoursePublishChecklistService
{
    private const MESSAGES = [
        'CATEGORY_NOT_FOUND' => '课程分类不存在', 'CATEGORY_DISABLED' => '课程分类已停用',
        'INTRO_REQUIRED' => '课程简介不能为空', 'SALE_WINDOW_EXPIRED' => '优惠价格不在有效时间内',
        'NO_PUBLISHABLE_CHAPTER' => '没有启用的章节', 'NO_PUBLISHABLE_LESSON' => '没有有效课节',
        'NOTIFICATION_IMPACT_UNAVAILABLE' => '暂时无法读取在册学员人数',
        'LESSON_INCOMPLETE' => '课节内容不完整', 'ASSET_PROCESSING' => '课节资源处理中',
        'ASSET_MISSING' => '课节资源缺失', 'ASSET_BROKEN' => '课节资源损坏',
        'ASSET_UNREACHABLE' => '课节资源暂时不可达', 'ASSET_PROBE_SKIPPED' => '资源探测超出时间预算',
        'PROGRESS_DENOMINATOR_CHANGES' => '进度将按有效课节重算，已完成课节保留',
        'NO_TRIAL_LESSON' => '没有试看课节', 'ALL_LESSONS_TRIAL' => '全部启用课节均可试看',
        'MAP_REFERENCE' => '已发布学习地图引用该课程', 'MAP_STEP_WILL_RECOVER' => '发布后学习地图异常步骤将恢复',
        'NOTIFICATION_WILL_SEND' => '发布将向全体在册学员发送课程发布消息',
        'NOTIFICATION_SKIPPED_ALREADY_PUBLISHED' => '课程已发布，本次不通知',
    ];

    /** @param \Closure(): array{count:int,max_id:int|null}|null $learnerSnapshot */
    public function __construct(
        private readonly AssetReachabilityProbe $probe = new NativeAssetReachabilityProbe(),
        private readonly ?\Closure $learnerSnapshot = null,
    ) {
    }

    /** @return array<string, mixed> */
    public function build(int $courseId, int $actorStaffAccountId): array
    {
        $course = Db::name('courses')->where('id', $courseId)->find();
        if (!$course) {
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }
        if ($actorStaffAccountId > 0) {
            DataScopeService::assertCourseAccessibleFromScope(
                (new DataScopeService())->resolveForCourses($actorStaffAccountId),
                (int) $course['department_id'],
                (int) $course['created_by_staff_id'],
                $actorStaffAccountId,
            );
        }
        $category = Db::name('categories')->where('id', (int) $course['category_id'])->find();
        $findings = [];
        $intro = HtmlSanitizer::sanitize((string) $course['intro_rich_text'])['html'];
        $priceValid = true;
        if ((float) $course['sale_price'] > 0) {
            $start = strtotime((string) $course['sale_start_at']);
            $end = strtotime((string) $course['sale_end_at']);
            $priceValid = $start && $end && $end > $start && time() >= $start && time() < $end;
        }
        if (!$category) {
            $findings[] = $this->finding('CATEGORY_NOT_FOUND', 'hard');
        } elseif ($category['status'] !== 'enabled') {
            $findings[] = $this->finding('CATEGORY_DISABLED', 'hard');
        }
        if (trim(html_entity_decode(strip_tags($intro), ENT_QUOTES | ENT_HTML5, 'UTF-8')) === '') {
            $findings[] = $this->finding('INTRO_REQUIRED', 'hard');
        }
        if (!$priceValid) {
            $findings[] = $this->finding('SALE_WINDOW_EXPIRED', 'hard');
        }
        $chapters = Db::name('chapters')->where('course_id', $courseId)->order('sort,id')->select()->toArray();
        $lessons = Db::name('lessons')->alias('l')->join('chapters c', 'c.id = l.chapter_id')
            ->leftJoin('assets a', 'a.id = l.asset_id')->where('c.course_id', $courseId)
            ->field('l.*,c.status AS chapter_status,a.status AS asset_status,a.storage_path')->order('l.sort,l.id')->select()->toArray();
        $byChapter = [];
        $probeDeadline = microtime(true) + 5;
        $effectiveCount = $trialCount = $enabledCount = 0;
        foreach ($lessons as $lesson) {
            $effective = self::isEffectiveLesson($lesson);
            $enabled = $lesson['chapter_status'] === 'enabled' && $lesson['status'] === 'enabled';
            $effectiveCount += (int) $effective;
            $enabledCount += (int) $enabled;
            $trialCount += (int) ($enabled && (bool) $lesson['is_preview']);
            $chapterId = (int) $lesson['chapter_id'];
            $lessonId = (int) $lesson['id'];
            $byChapter[$chapterId][] = [
                'id' => $lessonId, 'title' => (string) $lesson['title'], 'sort' => (int) $lesson['sort'],
                'status' => (string) $lesson['status'], 'content_type' => (string) $lesson['content_type'],
                'is_preview' => (bool) $lesson['is_preview'], 'is_effective' => $effective,
                'asset_id' => $lesson['asset_id'] ? (int) $lesson['asset_id'] : null,
                'asset_status' => $lesson['asset_status'],
            ];
            if ($enabled && !$effective) {
                $code = $lesson['content_type'] === 'markdown' ? 'LESSON_INCOMPLETE' : match ($lesson['asset_status']) {
                    'processing' => 'ASSET_PROCESSING', 'broken' => 'ASSET_BROKEN', default => 'ASSET_MISSING',
                };
                $findings[] = $this->finding($code, 'warning', $chapterId, $lessonId);
            }
            if ($effective && $lesson['content_type'] !== 'markdown') {
                try {
                    $probe = microtime(true) >= $probeDeadline
                        ? ['reachable' => false, 'skipped' => true]
                        : $this->probe->probe((string) $lesson['storage_path']);
                } catch (\Throwable) {
                    $probe = ['reachable' => false, 'skipped' => false];
                }
                if ($probe['skipped'] || !$probe['reachable']) {
                    $findings[] = $this->finding($probe['skipped'] ? 'ASSET_PROBE_SKIPPED' : 'ASSET_UNREACHABLE', 'warning', $chapterId, $lessonId);
                }
            }
        }
        $catalogChapters = [];
        foreach ($chapters as $chapter) {
            $items = $byChapter[(int) $chapter['id']] ?? [];
            $catalogChapters[] = [
                'id' => (int) $chapter['id'], 'title' => (string) $chapter['title'], 'sort' => (int) $chapter['sort'],
                'status' => (string) $chapter['status'], 'is_effective_chapter' => in_array(true, array_column($items, 'is_effective'), true),
                'lessons' => $items,
            ];
        }
        if (!in_array('enabled', array_column($chapters, 'status'), true)) {
            $findings[] = $this->finding('NO_PUBLISHABLE_CHAPTER', 'hard');
        }
        if ($effectiveCount === 0) {
            $findings[] = $this->finding('NO_PUBLISHABLE_LESSON', 'hard');
        }
        if ($trialCount === 0) {
            $findings[] = $this->finding('NO_TRIAL_LESSON', 'info');
        } elseif ($trialCount === $enabledCount) {
            $findings[] = $this->finding('ALL_LESSONS_TRIAL', 'info');
        }
        $catalog = [
            'category_id' => $course['category_id'] ? (int) $course['category_id'] : null,
            'category_name' => $category['name'] ?? null, 'category_enabled' => ($category['status'] ?? null) === 'enabled',
            'intro_present' => !in_array('INTRO_REQUIRED', array_column($findings, 'code'), true),
            'price_mode' => (string) $course['price_mode'], 'price_valid' => (bool) $priceValid,
            'effective_chapter_count' => count(array_filter($catalogChapters, static fn(array $c): bool => $c['is_effective_chapter'])),
            'effective_lesson_count' => $effectiveCount, 'trial_lesson_count' => $trialCount, 'chapters' => $catalogChapters,
        ];
        $willDispatch = $course['status'] !== 'published';
        $maps = Db::name('learning_maps')->alias('m')->join('map_stages ms', 'ms.map_id = m.id')
            ->join('map_stage_courses mc', 'mc.stage_id = ms.id')->where('mc.course_id', $courseId)
            ->field('m.id,m.title,m.status')->distinct(true)->order('m.id')->select()->toArray();
        $mapItems = [];
        $draftMaps = 0;
        foreach ($maps as $map) {
            if ($map['status'] === 'draft') {
                $draftMaps++;
            }
            if ($map['status'] !== 'published') {
                continue;
            }
            $mapItems[] = ['id' => (int) $map['id'], 'title' => (string) $map['title'], 'will_recover_abnormal_step' => $willDispatch];
        }
        if ($mapItems !== []) {
            $findings[] = $this->finding($willDispatch ? 'MAP_STEP_WILL_RECOVER' : 'MAP_REFERENCE', 'info');
        }
        $enrollmentCount = (int) Db::name('course_enrollments')->where('course_id', $courseId)->count();
        $currentDenominator = count(array_filter($lessons, static fn(array $l): bool => $l['status'] === 'enabled'));
        $willRecalculate = $enrollmentCount > 0 && $currentDenominator !== $effectiveCount;
        if ($willRecalculate) {
            $findings[] = $this->finding('PROGRESS_DENOMINATOR_CHANGES', 'warning');
        }
        $unavailable = false;
        try {
            $recipientCount = ($this->learnerSnapshot !== null ? ($this->learnerSnapshot)() : (new NotificationDispatchService())->activeLearnerSnapshot())['count'];
        } catch (\Throwable) {
            $recipientCount = null;
            $unavailable = true;
            $findings[] = $this->finding('NOTIFICATION_IMPACT_UNAVAILABLE', 'hard');
        }
        $findings[] = $this->finding($willDispatch ? 'NOTIFICATION_WILL_SEND' : 'NOTIFICATION_SKIPPED_ALREADY_PUBLISHED', 'info');
        $hardCount = count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'hard'));
        return [
            'course_id' => $courseId, 'course_title' => (string) $course['title'], 'course_status' => (string) $course['status'],
            'generated_at' => ShanghaiTime::now()->format(DATE_ATOM),
            'content_fingerprint' => hash('sha256', json_encode($catalog, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE)),
            'hard_error_count' => $hardCount,
            'warning_count' => count(array_filter($findings, static fn(array $f): bool => $f['severity'] === 'warning')),
            'can_publish' => $hardCount === 0 && !$unavailable, 'catalog' => $catalog, 'findings' => $findings,
            'impact' => [
                'maps' => ['published_count' => count($mapItems), 'draft_count' => $draftMaps, 'items' => $mapItems],
                'entitlements' => ['active_count' => (int) Db::name('course_entitlements')->where('course_id', $courseId)->where('status', 'active')->count()],
                'progress' => ['enrollment_count' => $enrollmentCount, 'current_denominator' => $currentDenominator, 'next_denominator' => $effectiveCount, 'will_recalculate' => $willRecalculate, 'completed_preserved' => true],
                'notification' => ['will_dispatch' => $willDispatch, 'recipient_count' => $recipientCount, 'recipient_unavailable' => $unavailable],
            ],
        ];
    }

    /** @param array<string, mixed> $lesson */
    public static function isEffectiveLesson(array $lesson): bool
    {
        return $lesson['chapter_status'] === 'enabled' && $lesson['status'] === 'enabled'
            && ($lesson['content_type'] === 'markdown'
                ? trim((string) ($lesson['body_markdown'] ?? '')) !== ''
                : in_array($lesson['content_type'], ['pdf', 'video'], true) && (int) ($lesson['asset_id'] ?? 0) > 0 && ($lesson['asset_status'] ?? null) === 'ready');
    }

    /** @return array<string, mixed> */
    private function finding(string $code, string $severity, ?int $chapterId = null, ?int $lessonId = null): array
    {
        return ['code' => $code, 'severity' => $severity, 'message' => self::MESSAGES[$code],
            'scope' => $lessonId !== null ? 'lesson' : ($chapterId !== null ? 'chapter' : 'course'),
            'chapter_id' => $chapterId, 'lesson_id' => $lessonId];
    }
}
