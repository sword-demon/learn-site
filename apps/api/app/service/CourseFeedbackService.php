<?php

declare(strict_types=1);

namespace App\service;

use App\model\CourseFeedback;
use App\service\DataScopeService;
use App\support\HtmlSanitizer;
use App\support\Logger;
use support\think\Db;

/**
 * CourseFeedbackService — private per-course learner feedback (010 US4/US5).
 *
 * Invariants (specs/010 data-model.md + research R7):
 *  - Submitters must hold an ACTIVE course entitlement; visitors and
 *    unauthorized learners never reach a write.
 *  - Body is stored only after HtmlSanitizer (same whitelist as course
 *    intro); admin renders it with v-html from the stored, sanitized copy.
 *  - Feedback never touches `reviews` — no shared code, no shared reads.
 *  - Status flips pending↔processed are audited (course_feedback.status_change).
 */
final class CourseFeedbackService
{
    private const TIMEZONE = 'Asia/Shanghai';
    private const BODY_MAX = 20_000;
    private const EXCERPT_MAX = 80;
    private const MAX_PAGE_LIMIT = 100;

    public function __construct(private readonly DataScopeService $scope = new DataScopeService())
    {
    }

    // -------------------------------------------------------------------------
    // 学习端:提交 (US4)
    // -------------------------------------------------------------------------

    /** @return array{id:int,course_id:int,status:string,created_at:string} */
    public function submit(int $learnerId, int $courseId, string $bodyHtml): array
    {
        if ($learnerId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, status, department_id, created_by_staff_id')
            ->find();
        if (!is_array($course) || (string) $course['status'] !== 'published') {
            // 与 PublicCatalogService::courseDetail 的可见性规则一致:
            // 草稿/下架不向学习端泄露, 即使历史访问权仍为 active。
            throw new BusinessException('NOT_FOUND', 'COURSE_NOT_FOUND');
        }

        // 无课程访问权者不得提交 (FR-024)。审核门槛在富文本解析前,
        // 避免无权请求消耗 DOM sanitizer 成本。
        $entitled = Db::name('course_entitlements')
            ->where('learner_id', $learnerId)
            ->where('course_id', $courseId)
            ->where('status', 'active')
            ->count();
        if ((int) $entitled === 0) {
            throw new BusinessException('FORBIDDEN', 'COURSE_ACCESS_REQUIRED');
        }

        $raw = (string) $bodyHtml;
        if (mb_strlen($raw) > self::BODY_MAX) {
            throw new BusinessException('VALIDATION_FAILED', 'FEEDBACK_BODY_TOO_LONG');
        }
        $sanitized = HtmlSanitizer::sanitize($raw)['html'];
        if ($this->visibleText($sanitized) === '') {
            throw new BusinessException('VALIDATION_FAILED', 'FEEDBACK_BODY_REQUIRED');
        }

        $now = $this->nowDatetime();
        $feedbackId = (int) CourseFeedback::create([
            'course_id' => $courseId,
            'learner_id' => $learnerId,
            'body_html' => $sanitized,
            'status' => CourseFeedback::STATUS_PENDING,
            'created_at' => $now,
            'updated_at' => $now,
        ])->id;

        Logger::info('course_feedback.submitted', [
            'feedback_id' => $feedbackId,
            'course_id' => $courseId,
            'learner_id' => $learnerId,
        ]);

        return [
            'id' => $feedbackId,
            'course_id' => $courseId,
            'status' => CourseFeedback::STATUS_PENDING,
            'created_at' => $now,
        ];
    }

    // -------------------------------------------------------------------------
    // 管理端:列表 / 详情 / 处理状态 (US5)
    // -------------------------------------------------------------------------

    /**
     * @param array{status?:string,page?:int,limit?:int} $filters
     * @return array{items:list<array<string,mixed>>,total:int,page:int,limit:int}
     */
    public function listFeedbacks(int $staffId, int $courseId, array $filters): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $status = (string) ($filters['status'] ?? '');
        if ($status !== '' && !in_array($status, [CourseFeedback::STATUS_PENDING, CourseFeedback::STATUS_PROCESSED], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'FEEDBACK_STATUS_INVALID');
        }
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(self::MAX_PAGE_LIMIT, (int) ($filters['limit'] ?? 20)));

        $query = Db::name('course_feedbacks')
            ->alias('cf')
            ->join('learners l', 'l.account_id = cf.learner_id')
            ->where('cf.course_id', $courseId);
        if ($status !== '') {
            $query->where('cf.status', $status);
        }
        $total = (int) (clone $query)->count();
        $rows = $query
            ->field('cf.id, cf.course_id, cf.learner_id, cf.body_html, cf.status, cf.created_at, cf.processed_at, l.nickname')
            ->order('cf.created_at', 'desc')
            ->order('cf.id', 'desc')
            ->page($page, $limit)
            ->select()->toArray();

        return [
            'items' => array_map([$this, 'shapeListItem'], is_array($rows) ? $rows : []),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /** @return array<string,mixed> */
    public function getFeedback(int $staffId, int $courseId, int $feedbackId): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        $row = Db::name('course_feedbacks')
            ->alias('cf')
            ->join('learners l', 'l.account_id = cf.learner_id')
            ->where('cf.id', $feedbackId)
            ->where('cf.course_id', $courseId)
            ->field('cf.id, cf.course_id, cf.learner_id, cf.body_html, cf.status, cf.created_at, cf.processed_at, cf.processed_by_staff_id, l.nickname')
            ->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'FEEDBACK_NOT_FOUND');
        }
        return $this->shapeDetail($row);
    }

    /**
     * Move a feedback between pending and processed. Both directions are
     * allowed (管理员可打回待处理) and every flip is audited.
     *
     * @return array<string,mixed>
     */
    public function updateStatus(int $staffId, int $courseId, int $feedbackId, string $status): array
    {
        $this->assertCourseAccessible($staffId, $courseId);
        if (!in_array($status, [CourseFeedback::STATUS_PENDING, CourseFeedback::STATUS_PROCESSED], true)) {
            throw new BusinessException('VALIDATION_FAILED', 'FEEDBACK_STATUS_INVALID');
        }
        $feedback = Db::name('course_feedbacks')
            ->where('id', $feedbackId)
            ->where('course_id', $courseId)
            ->find();
        if (!is_array($feedback)) {
            throw new BusinessException('NOT_FOUND', 'FEEDBACK_NOT_FOUND');
        }
        if ((string) $feedback['status'] === $status) {
            // 幂等:重复标记同一状态不产生新审计行。
            return $this->getFeedback($staffId, $courseId, $feedbackId);
        }

        $now = $this->nowDatetime();
        $processedAt = $status === CourseFeedback::STATUS_PROCESSED ? $now : null;
        $processedBy = $status === CourseFeedback::STATUS_PROCESSED ? $staffId : null;
        Db::transaction(function () use ($feedbackId, $status, $processedAt, $processedBy, $now): void {
            Db::name('course_feedbacks')->where('id', $feedbackId)->update([
                'status' => $status,
                'processed_at' => $processedAt,
                'processed_by_staff_id' => $processedBy,
                'updated_at' => $now,
            ]);
        });

        $this->writeAudit($staffId, 'course_feedback.status_change', $feedbackId, [
            'course_id' => $courseId,
            'from' => (string) $feedback['status'],
            'to' => $status,
        ]);

        return $this->getFeedback($staffId, $courseId, $feedbackId);
    }

    // -------------------------------------------------------------------------
    // 内部工具
    // -------------------------------------------------------------------------

    private function visibleText(string $html): string
    {
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5));
        // &nbsp; 等空白实体与零宽字符不算可见文本。
        $collapsed = preg_replace('/[\s\x{00A0}\x{2007}\x{202F}\x{200B}\x{FEFF}]+/u', '', $text);
        return $collapsed ?? '';
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeListItem(array $row): array
    {
        $excerpt = mb_substr(trim(strip_tags((string) $row['body_html'])), 0, self::EXCERPT_MAX);
        return [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'learner' => $this->identity((int) $row['learner_id'], (string) ($row['nickname'] ?? '')),
            'body_excerpt' => $excerpt,
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'processed_at' => $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
        ];
    }

    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function shapeDetail(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'course_id' => (int) $row['course_id'],
            'learner' => $this->identity((int) $row['learner_id'], (string) ($row['nickname'] ?? '')),
            // 服务端已消毒,管理端 v-html 安全渲染 (R7)。
            'body_html' => (string) $row['body_html'],
            'status' => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
            'processed_at' => $row['processed_at'] !== null ? (string) $row['processed_at'] : null,
            'processed_by_staff_id' => isset($row['processed_by_staff_id']) && $row['processed_by_staff_id'] !== null
                ? (int) $row['processed_by_staff_id']
                : null,
        ];
    }

    /** @return array{account_id:int,nickname:string} 公开身份与学员名单规则一致 */
    private function identity(int $learnerId, string $nickname): array
    {
        return [
            'account_id' => $learnerId,
            'nickname' => $nickname !== '' ? $nickname : '匿名学员',
        ];
    }

    /** @return array<string,mixed> */
    private function assertCourseAccessible(int $staffId, int $courseId): array
    {
        $course = Db::name('courses')
            ->where('id', $courseId)
            ->field('id, title, department_id, created_by_staff_id')
            ->find();
        if (!is_array($course)) {
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

    /** @param array<string,mixed> $payload */
    private function writeAudit(int $staffId, string $action, int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => $staffId,
            'action' => $action,
            'target_type' => 'course_feedback',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE),
            'created_at' => $this->nowDatetime(),
        ]);
    }

    private function nowDatetime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }
}
