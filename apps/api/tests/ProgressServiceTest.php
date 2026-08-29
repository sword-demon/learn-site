<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\EntitlementService;
use App\service\ProgressService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\ThinkOrm\ThinkOrm;

final class ProgressServiceTest extends TestCase
{
    private ProgressService $service;
    private int $learnerId;
    private int $courseId;
    private int $markdownLessonId;
    private int $pdfLessonId;
    private int $videoLessonId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::startTrans();
        try {
            $this->service = new ProgressService(new EntitlementService());
            $this->seedFixture();
        } catch (Throwable $exception) {
            // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        // @phpstan-ignore-next-line ThinkORM exposes transaction methods through a facade.
        Db::rollback();
    }

    public function testMarkdownMustBeOpenedBeforeItCanBeCompleted(): void
    {
        $first = $this->service->reportProgress(
            $this->learnerId,
            $this->markdownLessonId,
            'markdown',
            999,
            1,
            true,
        );
        self::assertFalse($first['completed']);
        self::assertNotNull($first['opened_at']);

        $second = $this->service->reportProgress(
            $this->learnerId,
            $this->markdownLessonId,
            'markdown',
            0,
            1,
            true,
        );
        self::assertTrue($second['completed']);
        self::assertNotNull($second['completed_at']);
    }

    public function testPdfUsesTheSameExplicitCompletionRule(): void
    {
        $opened = $this->service->reportProgress(
            $this->learnerId,
            $this->pdfLessonId,
            'pdf',
            0,
            1,
            false,
        );
        self::assertFalse($opened['completed']);

        $completed = $this->service->reportProgress(
            $this->learnerId,
            $this->pdfLessonId,
            'pdf',
            0,
            1,
            true,
        );
        self::assertTrue($completed['completed']);
    }

    public function testVideoUsesPersistedDurationAndCompletesAtNinetyPercent(): void
    {
        $beforeThreshold = $this->service->reportProgress(
            $this->learnerId,
            $this->videoLessonId,
            'video',
            1,
            89,
            true,
        );
        self::assertFalse($beforeThreshold['completed']);

        $atThreshold = $this->service->reportProgress(
            $this->learnerId,
            $this->videoLessonId,
            'video',
            1,
            90,
            false,
        );
        self::assertTrue($atThreshold['completed']);
    }

    public function testPositionAndCompletionNeverRegress(): void
    {
        $complete = $this->service->reportProgress(
            $this->learnerId,
            $this->videoLessonId,
            'video',
            100,
            95,
            false,
        );
        self::assertTrue($complete['completed']);

        $behind = $this->service->reportProgress(
            $this->learnerId,
            $this->videoLessonId,
            'video',
            100,
            10,
            false,
        );
        self::assertSame(95, $behind['position_seconds']);
        self::assertTrue($behind['completed']);

        $enrollment = Db::name('course_enrollments')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->find();
        self::assertSame(33, (int) $enrollment['progress_percent']);
    }

    public function testInactiveEntitlementCannotWriteProgress(): void
    {
        Db::name('course_entitlements')
            ->where('learner_id', $this->learnerId)
            ->where('course_id', $this->courseId)
            ->update(['status' => 'revoked']);

        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('LESSON_LOCKED');
        $this->service->reportProgress(
            $this->learnerId,
            $this->markdownLessonId,
            'markdown',
            0,
            1,
            false,
        );
    }

    private function seedFixture(): void
    {
        $now = '2026-08-28 10:00:00';
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => 'Progress test department ' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'Progress test category ' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $staffAccountId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => 'progress-staff-' . bin2hex(random_bytes(3)),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $staffAccountId,
            'is_super_admin' => 1,
            'department_id' => $departmentId,
            'display_name' => 'Progress test staff',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . str_pad((string) random_int(0, 999999999), 8, '0', STR_PAD_LEFT),
            'password_hash' => 'not-used',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $this->learnerId,
            'nickname' => '进度测试学员',
            'avatar_url' => null,
            'show_on_course' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => 'Progress test course',
            'cover_url' => null,
            'teacher_name' => 'Test teacher',
            'summary' => 'Progress test',
            'intro_rich_text' => '<p>Progress test</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $staffAccountId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $this->courseId,
            'title' => 'Progress chapter',
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->markdownLessonId = $this->insertLesson($chapterId, 'Markdown lesson', 'markdown', 0, $now);
        $this->pdfLessonId = $this->insertLesson($chapterId, 'PDF lesson', 'pdf', 0, $now);
        $this->videoLessonId = $this->insertLesson($chapterId, 'Video lesson', 'video', 100, $now);

        (new EntitlementService())->grant($this->learnerId, $this->courseId, 'free');
    }

    private function insertLesson(int $chapterId, string $title, string $type, int $duration, string $now): int
    {
        return (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => $title,
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => $type,
            'body_markdown' => $type === 'markdown' ? '# Test' : null,
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => $duration,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
