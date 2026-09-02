<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\CourseFeedbackService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

/**
 * 010-course-notify-feedback-codes / US4 + US5 — private course feedback.
 */
final class CourseFeedbackTest extends TestCase
{
    private int $staffId;
    private CourseFeedbackService $service;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $this->staffId = $this->insertStaff(true);
        $this->service = new CourseFeedbackService();
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    // ── US4: 提交 ─────────────────────────────────────────────────────────

    public function testEntitledLearnerCanSubmitRichFeedback(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);

        $result = $this->service->submit(
            $learnerId,
            $courseId,
            '<p>希望增加练习</p><ul><li>课后题</li><li>实战案例</li></ul>',
        );

        self::assertSame($courseId, $result['course_id']);
        self::assertSame('pending', $result['status']);
        $row = Db::name('course_feedbacks')->where('id', $result['id'])->find();
        self::assertIsArray($row);
        self::assertSame('<p>希望增加练习</p><ul><li>课后题</li><li>实战案例</li></ul>', (string) $row['body_html']);
        self::assertSame($learnerId, (int) $row['learner_id']);
    }

    public function testSubmitSanitizesDangerousHtml(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);

        $result = $this->service->submit(
            $learnerId,
            $courseId,
            '<p>好的课程</p><script>alert(1)</script><a href="javascript:alert(2)">链接</a>',
        );

        $stored = (string) Db::name('course_feedbacks')->where('id', $result['id'])->value('body_html');
        self::assertStringNotContainsString('<script', $stored);
        self::assertStringNotContainsString('javascript:', $stored);
        self::assertStringContainsString('好的课程', $stored);
    }

    public function testSubmitAllowsMultipleFeedbacksPerCourse(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);

        $first = $this->service->submit($learnerId, $courseId, '<p>第一条</p>');
        $second = $this->service->submit($learnerId, $courseId, '<p>第二条</p>');

        self::assertNotSame($first['id'], $second['id']);
        self::assertSame(
            2,
            (int) Db::name('course_feedbacks')->where('course_id', $courseId)->count(),
        );
    }

    public function testSubmitRequiresVisibleText(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);

        foreach (['', '    ', '<p></p>', '<p>&nbsp;</p>'] as $body) {
            try {
                $this->service->submit($learnerId, $courseId, $body);
                self::fail("body without visible text must be rejected: [{$body}]");
            } catch (BusinessException $e) {
                self::assertSame('FEEDBACK_BODY_REQUIRED', $e->getMessage());
            }
        }
        self::assertSame(
            0,
            (int) Db::name('course_feedbacks')->where('course_id', $courseId)->count(),
        );
    }

    public function testSubmitRejectsOversizedBody(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $body = '<p>' . str_repeat('字', 20_001) . '</p>';

        try {
            $this->service->submit($learnerId, $courseId, $body);
            self::fail('oversized body must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('FEEDBACK_BODY_TOO_LONG', $e->getMessage());
        }
    }

    public function testSubmitRequiresActiveEntitlement(): void
    {
        $courseId = $this->insertCourse();
        $outsider = $this->insertLearner(null);

        try {
            $this->service->submit($outsider, $courseId, '<p>我没有访问权</p>');
            self::fail('learner without entitlement must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_ACCESS_REQUIRED', $e->getMessage());
        }

        // 访问权被撤销后同样不可提交。
        $revoked = $this->insertLearner($courseId, 'revoked');
        try {
            $this->service->submit($revoked, $courseId, '<p>授权已被撤销</p>');
            self::fail('revoked learner must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_ACCESS_REQUIRED', $e->getMessage());
        }
    }

    public function testSubmitOnUnknownOrUnpublishedCourseIsNotFound(): void
    {
        $learnerId = $this->insertLearner(null);
        try {
            $this->service->submit($learnerId, 987654321, '<p>课程不存在</p>');
            self::fail('unknown course must 404');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_NOT_FOUND', $e->getMessage());
        }

        $courseId = $this->insertCourse();
        $entitled = $this->insertLearner($courseId);
        Db::name('courses')->where('id', $courseId)->update(['status' => 'unpublished']);
        try {
            $this->service->submit($entitled, $courseId, '<p>课程已下架</p>');
            self::fail('unpublished course must stay hidden from feedback submission');
        } catch (BusinessException $e) {
            self::assertSame('COURSE_NOT_FOUND', $e->getMessage());
        }
    }

    public function testFeedbackNeverEntersReviews(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $this->service->submit($learnerId, $courseId, '<p>私密意见, 不应出现在评价区</p>');

        self::assertSame(
            0,
            (int) Db::name('reviews')->where('course_id', $courseId)->count(),
        );
    }

    // ── US5: 管理端 ───────────────────────────────────────────────────────

    public function testAdminListOrdersFiltersAndExcerpts(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId, 'active', '反馈小明');
        $body = '<p>' . implode('', array_fill(0, 60, '内容')) . '</p><script>x()</script>';
        $this->service->submit($learnerId, $courseId, $body);
        $this->service->submit($learnerId, $courseId, '<p>待处理条目二</p>');

        $list = $this->service->listFeedbacks($this->staffId, $courseId, []);
        self::assertSame(2, $list['total']);
        $latest = $list['items'][0];
        self::assertSame('待处理条目二', $latest['body_excerpt']);
        self::assertSame('pending', $latest['status']);
        self::assertSame('反馈小明', $latest['learner']['nickname']);
        self::assertSame($learnerId, $latest['learner']['account_id']);
        // 摘要不含标签, 不含完整正文。
        self::assertSame(mb_strlen($list['items'][1]['body_excerpt']), 80);

        $processedPending = $this->service->listFeedbacks($this->staffId, $courseId, ['status' => 'pending']);
        self::assertSame(2, $processedPending['total']);
    }

    public function testAdminDetailShowsSanitizedBody(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $created = $this->service->submit($learnerId, $courseId, '<p>详情正文</p>');

        $detail = $this->service->getFeedback($this->staffId, $courseId, $created['id']);
        self::assertSame('<p>详情正文</p>', $detail['body_html']);
        self::assertNull($detail['processed_at']);
        self::assertNull($detail['processed_by_staff_id']);
        self::assertSame($learnerId, $detail['learner']['account_id']);
    }

    public function testAdminStatusChangeIsAuditedAndReversible(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $created = $this->service->submit($learnerId, $courseId, '<p>处理我</p>');

        $processed = $this->service->updateStatus($this->staffId, $courseId, $created['id'], 'processed');
        self::assertSame('processed', $processed['status']);
        self::assertNotNull($processed['processed_at']);
        self::assertSame($this->staffId, $processed['processed_by_staff_id']);
        self::assertSame(
            1,
            (int) Db::name('audit_log')->where('action', 'course_feedback.status_change')->count(),
        );

        // 打回待处理同样允许, 审计再记一笔。
        $reopened = $this->service->updateStatus($this->staffId, $courseId, $created['id'], 'pending');
        self::assertSame('pending', $reopened['status']);
        self::assertNull($reopened['processed_at']);
        self::assertSame(
            2,
            (int) Db::name('audit_log')->where('action', 'course_feedback.status_change')->count(),
        );

        // 幂等:同状态重复标记不产生新审计。
        $this->service->updateStatus($this->staffId, $courseId, $created['id'], 'pending');
        self::assertSame(
            2,
            (int) Db::name('audit_log')->where('action', 'course_feedback.status_change')->count(),
        );
    }

    public function testAdminAccessRequiresCourseScope(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $created = $this->service->submit($learnerId, $courseId, '<p>范围外正文</p>');

        // 无任何角色、非超管的员工:不在课程部门数据范围内。
        $limited = new CourseFeedbackService();
        $outsiderId = $this->insertStaff(false);
        try {
            $limited->listFeedbacks($outsiderId, $courseId, []);
            self::fail('out-of-scope staff must be rejected on list');
        } catch (BusinessException $e) {
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $e->getMessage());
        }
        try {
            $limited->getFeedback($outsiderId, $courseId, $created['id']);
            self::fail('out-of-scope staff must be rejected on detail');
        } catch (BusinessException $e) {
            self::assertSame('DEPARTMENT_OUT_OF_SCOPE', $e->getMessage());
        }
    }

    public function testUnknownFeedbackOrStatusIsRejected(): void
    {
        $courseId = $this->insertCourse();
        $learnerId = $this->insertLearner($courseId);
        $this->service->submit($learnerId, $courseId, '<p>存在</p>');

        try {
            $this->service->getFeedback($this->staffId, $courseId, 999999);
            self::fail('unknown feedback must 404');
        } catch (BusinessException $e) {
            self::assertSame('FEEDBACK_NOT_FOUND', $e->getMessage());
        }
        try {
            $this->service->updateStatus($this->staffId, $courseId, 999999, 'processed');
            self::fail('unknown feedback update must 404');
        } catch (BusinessException $e) {
            self::assertSame('FEEDBACK_NOT_FOUND', $e->getMessage());
        }
        try {
            $created = $this->service->submit($learnerId, $courseId, '<p>再来一条</p>');
            $this->service->updateStatus($this->staffId, $courseId, $created['id'], 'closed');
            self::fail('invalid status must be rejected');
        } catch (BusinessException $e) {
            self::assertSame('FEEDBACK_STATUS_INVALID', $e->getMessage());
        }
    }

    // ── helpers ───────────────────────────────────────────────────────────

    private function insertStaff(bool $superAdmin): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'fb-admin-' . bin2hex(random_bytes(4)),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $id,
            'is_super_admin' => $superAdmin ? 1 : 0,
            'department_id' => null,
            'display_name' => 'Feedback Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $id;
    }

    private function insertLearner(?int $entitledCourseId, string $entitlementStatus = 'active', ?string $nickname = null): int
    {
        $now = date('Y-m-d H:i:s');
        $id = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '13' . random_int(100000000, 999999999),
            'password_hash' => 'hash',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $id,
            'nickname' => $nickname ?? ('学员' . random_int(1000, 9999)),
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($entitledCourseId !== null) {
            Db::name('course_entitlements')->insert([
                'learner_id' => $id,
                'course_id' => $entitledCourseId,
                'source' => 'purchase',
                'order_id' => null,
                'status' => $entitlementStatus,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return $id;
    }

    private function insertCourse(): int
    {
        $now = date('Y-m-d H:i:s');
        $deptId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'fb-dept-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'name' => 'fb-cat-' . bin2hex(random_bytes(3)),
            'parent_id' => 0,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return (int) Db::name('courses')->insertGetId([
            'department_id' => $deptId,
            'category_id' => $categoryId,
            'title' => '意见反馈测试课 ' . bin2hex(random_bytes(3)),
            'teacher_name' => '教师丙',
            'summary' => '意见反馈验证摘要',
            'intro_rich_text' => '<p>课程简介</p>',
            'status' => 'published',
            'price_mode' => 'paid',
            'list_price' => 99,
            'sale_price' => 0,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
