<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\Authorize;
use App\middleware\LearnerAuth;
use App\middleware\OptionalLearnerAuth;
use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\ReviewService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Throwable;
use Webman\Route;
use Webman\ThinkOrm\ThinkOrm;

final class ReviewServiceIntegrationTest extends TestCase
{
    private ReviewService $service;
    private int $actorId;
    private int $outsideStaffId;
    private int $visibleLearnerId;
    private int $privateLearnerId;
    private int $incompleteLearnerId;
    private int $unauthorizedLearnerId;
    private int $outsideLearnerId;
    private int $courseId;
    private int $lessonId;
    private int $outsideCourseId;
    private int $outsideLessonId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        try {
            $this->service = new ReviewService(
                new EntitlementService(),
                new DataScopeService(),
            );
            $this->seedFixture();
        } catch (Throwable $exception) {
            Db::rollback();
            throw $exception;
        }
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testPostingRequiresACompletedLesson(): void
    {
        $this->assertBusinessException(
            fn (): array => $this->service->postReview(
                $this->incompleteLearnerId,
                $this->courseId,
                5,
                'Entitlement alone is not enough.',
            ),
            'FORBIDDEN',
            'REVIEW_REQUIRES_COMPLETED_LESSON',
        );

        $this->assertSame(
            0,
            (int) Db::name('reviews')->where('learner_id', $this->incompleteLearnerId)->count(),
        );
    }

    public function testPostingReturnsAThreadAndRejectsDuplicateActiveReview(): void
    {
        $thread = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'A useful course.',
        );

        $this->assertSame('A useful course.', $thread['review']['body'] ?? null);
        $this->assertSame('Visible Learner', $thread['review']['author_name'] ?? null);
        $this->assertNull($thread['review']['learner_id'] ?? null);
        $this->assertFalse($thread['review']['edited'] ?? true);
        $this->assertSame([], $thread['replies'] ?? null);

        $this->assertBusinessException(
            fn (): array => $this->service->postReview(
                $this->visibleLearnerId,
                $this->courseId,
                4,
                'A duplicate review.',
            ),
            'CONFLICT',
            'REVIEW_ALREADY_EXISTS',
        );
    }

    public function testPublicViewsUseConsentedNamesWithoutExposingAccountIds(): void
    {
        $visible = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'Public profile review.',
        );
        $private = $this->service->postReview(
            $this->privateLearnerId,
            $this->courseId,
            4,
            'Private profile review.',
        );

        $list = $this->service->listForCourse($this->courseId);
        $byId = [];
        foreach ($list['items'] as $review) {
            $byId[(int) $review['id']] = $review;
        }

        $visibleReviewId = (int) ($visible['review']['id'] ?? 0);
        $privateReviewId = (int) ($private['review']['id'] ?? 0);
        $this->assertSame('Visible Learner', $byId[$visibleReviewId]['author_name'] ?? null);
        $this->assertSame('匿名学员', $byId[$privateReviewId]['author_name'] ?? null);
        $this->assertNull($byId[$visibleReviewId]['learner_id'] ?? null);
        $this->assertNull($byId[$privateReviewId]['learner_id'] ?? null);

        $reply = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->privateLearnerId,
            $visibleReviewId,
            null,
            'Anonymous reply.',
        ));
        $this->assertSame('匿名学员', $reply['author_name'] ?? null);
        $this->assertNull($reply['author_learner_id'] ?? null);
        $this->assertNull($reply['author_staff_id'] ?? null);
        $this->assertSame('public', $reply['visibility'] ?? null);
        $this->assertFalse($reply['edited'] ?? true);

        $thread = $this->callFeature(fn () => $this->service->showThread($visibleReviewId));
        $this->assertCount(1, $thread['replies'] ?? []);
    }

    public function testPublicViewsExposeViewerOwnershipWithoutAccountIds(): void
    {
        $ownThread = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'Owned review.',
        );
        $otherThread = $this->service->postReview(
            $this->privateLearnerId,
            $this->courseId,
            4,
            'Another learner review.',
        );
        $ownReviewId = (int) ($ownThread['review']['id'] ?? 0);
        $otherReviewId = (int) ($otherThread['review']['id'] ?? 0);

        $guestList = $this->service->listForCourse($this->courseId);
        foreach ($guestList['items'] as $review) {
            $this->assertFalse($review['viewer_owned'] ?? true);
            $this->assertNull($review['learner_id'] ?? null);
        }

        $viewerList = $this->service->listForCourse(
            $this->courseId,
            1,
            1,
            $this->visibleLearnerId,
        );
        $byId = [];
        foreach ($viewerList['items'] as $review) {
            $byId[(int) $review['id']] = $review;
        }
        $this->assertArrayNotHasKey($ownReviewId, $byId);
        $this->assertFalse($byId[$otherReviewId]['viewer_owned'] ?? true);
        $this->assertSame($ownReviewId, $viewerList['viewer_review']['id'] ?? null);
        $this->assertTrue($viewerList['viewer_review']['viewer_owned'] ?? false);
        $this->assertNull($viewerList['viewer_review']['learner_id'] ?? null);

        $reply = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->visibleLearnerId,
            $otherReviewId,
            null,
            'My reply.',
        ));
        $this->assertTrue($reply['viewer_owned'] ?? false);
        $this->assertNull($reply['author_learner_id'] ?? null);

        $thread = $this->service->showThread($otherReviewId, $this->visibleLearnerId);
        $this->assertFalse($thread['review']['viewer_owned'] ?? true);
        $this->assertTrue($thread['replies'][0]['viewer_owned'] ?? false);
    }

    public function testLearnerCanEditAndDeleteOnlyTheirOwnReview(): void
    {
        $thread = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            3,
            'Original review.',
        );
        $reviewId = (int) ($thread['review']['id'] ?? 0);

        Db::name('reviews')->where('id', $reviewId)->update([
            'created_at' => '2026-01-01 00:00:00',
            'updated_at' => '2026-01-01 00:00:00',
        ]);
        $edited = $this->callFeature(fn () => $this->service->updateReview(
            $this->visibleLearnerId,
            $reviewId,
            5,
            'Edited review.',
        ));
        $this->assertSame(5, $edited['review']['rating'] ?? null);
        $this->assertSame('Edited review.', $edited['review']['body'] ?? null);
        $this->assertTrue($edited['review']['edited'] ?? false);

        $this->assertBusinessException(
            fn (): array => $this->callFeature(fn () => $this->service->updateReview(
                $this->privateLearnerId,
                $reviewId,
                1,
                'Must not be persisted.',
            )),
            'FORBIDDEN',
            'NOT_REVIEW_OWNER',
        );

        $this->callFeature(fn () => $this->service->deleteReview($this->visibleLearnerId, $reviewId));
        $this->assertSame(0, (int) Db::name('reviews')->where('id', $reviewId)->count());

        $replacement = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            4,
            'Replacement review.',
        );
        $this->assertSame('Replacement review.', $replacement['review']['body'] ?? null);
    }

    public function testRepliesAllowThreeLevelsAndRejectAFourth(): void
    {
        $thread = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'Threaded review.',
        );
        $reviewId = (int) ($thread['review']['id'] ?? 0);

        $root = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->privateLearnerId,
            $reviewId,
            null,
            'Level one.',
        ));
        $child = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->visibleLearnerId,
            $reviewId,
            (int) $root['id'],
            'Level two.',
        ));
        $leaf = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->privateLearnerId,
            $reviewId,
            (int) $child['id'],
            'Level three.',
        ));

        $this->assertSame((int) $child['id'], $leaf['parent_id']);
        $this->assertBusinessException(
            fn (): array => $this->callFeature(fn () => $this->service->replyAsLearner(
                $this->visibleLearnerId,
                $reviewId,
                (int) $leaf['id'],
                'Level four.',
            )),
            'VALIDATION_FAILED',
            'REPLY_DEPTH_EXCEEDED',
        );
        $this->assertBusinessException(
            fn (): array => $this->callFeature(fn () => $this->service->replyAsLearner(
                $this->unauthorizedLearnerId,
                $reviewId,
                null,
                'No entitlement.',
            )),
            'FORBIDDEN',
            'NOT_AUTHORIZED',
        );
    }

    public function testModerationIsScopedAuditedAndHidesDescendantsFromPublicViews(): void
    {
        $inside = $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'Inside scope.',
        );
        $outside = $this->service->postReview(
            $this->outsideLearnerId,
            $this->outsideCourseId,
            4,
            'Outside scope.',
        );
        $insideReviewId = (int) ($inside['review']['id'] ?? 0);
        $outsideReviewId = (int) ($outside['review']['id'] ?? 0);

        $insideList = $this->callFeature(fn () => $this->service->listForModeration(
            $this->actorId,
            $this->courseId,
            'all',
        ));
        $outsideList = $this->callFeature(fn () => $this->service->listForModeration(
            $this->actorId,
            $this->outsideCourseId,
            'all',
        ));
        $this->assertSame([$insideReviewId], array_map('intval', array_column($insideList['items'], 'id')));
        $this->assertSame([], $outsideList['items']);

        foreach (
            [
                fn () => $this->service->showForModeration($this->actorId, $outsideReviewId),
                fn () => $this->service->hideReview($this->actorId, $outsideReviewId, 'Forbidden action.'),
                fn () => $this->service->replyAsAdmin(
                    $this->actorId,
                    $outsideReviewId,
                    null,
                    'Forbidden reply.',
                ),
            ] as $operation
        ) {
            $this->assertBusinessException(
                fn (): array => $this->callFeature($operation),
                'FORBIDDEN',
                'DEPARTMENT_OUT_OF_SCOPE',
            );
        }

        $hidden = $this->service->hideReview($this->actorId, $insideReviewId, 'Contains an advertisement.');
        $this->assertSame('hidden', $hidden['review']['visibility'] ?? null);
        $this->assertSame('Contains an advertisement.', $hidden['review']['hidden_reason'] ?? null);
        $restored = $this->service->restoreReview($this->actorId, $insideReviewId);
        $this->assertSame('public', $restored['review']['visibility'] ?? null);

        $root = $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->privateLearnerId,
            $insideReviewId,
            null,
            'Parent reply.',
        ));
        $this->callFeature(fn () => $this->service->replyAsLearner(
            $this->visibleLearnerId,
            $insideReviewId,
            (int) $root['id'],
            'Child reply.',
        ));

        $adminThread = $this->callFeature(fn () => $this->service->hideReply(
            $this->actorId,
            (int) $root['id'],
            'Parent is abusive.',
        ));
        $this->assertCount(2, $adminThread['replies'] ?? []);
        $this->assertSame('hidden', $adminThread['replies'][0]['visibility'] ?? null);
        $this->assertSame([], $this->service->showThread($insideReviewId)['replies'] ?? null);

        $this->callFeature(fn () => $this->service->restoreReply($this->actorId, (int) $root['id']));
        $this->assertCount(2, $this->service->showThread($insideReviewId)['replies'] ?? []);
        $this->assertSame(
            4,
            (int) Db::name('moderation_logs')->where('staff_id', $this->actorId)->count(),
        );
    }

    public function testModerationFilterOptionsOnlyExposeCoursesInsideTheStaffScope(): void
    {
        $this->service->postReview(
            $this->visibleLearnerId,
            $this->courseId,
            5,
            'Inside filter scope.',
        );
        $this->service->postReview(
            $this->outsideLearnerId,
            $this->outsideCourseId,
            4,
            'Outside filter scope.',
        );

        $options = $this->callFeature(fn () => $this->service->moderationFilterOptions($this->actorId));

        $this->assertSame(
            [$this->courseId],
            array_map('intval', array_column($options['courses'] ?? [], 'id')),
        );
    }

    public function testReviewRoutesExposePublicReadsAndProtectedWrites(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = $route->getMiddleware();
            }
        }

        foreach (
            [
                'PATCH /api/learner/v1/reviews/{id}',
                'DELETE /api/learner/v1/reviews/{id}',
                'GET /api/admin/v1/reviews/filter-options',
                'POST /api/admin/v1/review-replies/{id}/hide',
                'POST /api/admin/v1/review-replies/{id}/restore',
            ] as $route
        ) {
            $this->assertArrayHasKey($route, $routes);
        }

        $this->assertNotContains(
            LearnerAuth::class,
            $routes['GET /api/learner/v1/courses/{courseId}/reviews'] ?? [],
        );
        $this->assertNotContains(
            LearnerAuth::class,
            $routes['GET /api/learner/v1/reviews/{id}'] ?? [],
        );
        foreach (
            [
                'GET /api/learner/v1/courses/{id}',
                'GET /api/learner/v1/courses/{courseId}/reviews',
                'GET /api/learner/v1/reviews/{id}',
            ] as $route
        ) {
            $this->assertContains(OptionalLearnerAuth::class, $routes[$route] ?? []);
        }
        $this->assertSame(
            'review.view',
            Authorize::permissionFor('/api/admin/v1/reviews/7/replies', 'POST'),
        );
        $this->assertSame(
            'review.moderate',
            Authorize::permissionFor('/api/admin/v1/review-replies/7/hide', 'POST'),
        );
    }

    private function seedFixture(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $departmentId = $this->insertDepartment("T070 scope $suffix", $now);
        $outsideDepartmentId = $this->insertDepartment("T070 outside $suffix", $now);
        $this->actorId = $this->insertStaff("t070-actor-$suffix", $departmentId, $now);
        $this->outsideStaffId = $this->insertStaff("t070-outside-$suffix", $outsideDepartmentId, $now);

        $roleId = (int) Db::name('roles')->insertGetId([
            'name' => "T070 dept $suffix",
            'code' => "t070-dept-$suffix",
            'data_scope' => 'dept',
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_role')->insert([
            'staff_user_id' => $this->actorId,
            'role_id' => $roleId,
        ]);

        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "T070 category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        [$this->courseId, $this->lessonId] = $this->insertCourseWithLesson(
            $departmentId,
            $categoryId,
            $this->outsideStaffId,
            "T070 in-scope $suffix",
            $now,
        );
        [$this->outsideCourseId, $this->outsideLessonId] = $this->insertCourseWithLesson(
            $outsideDepartmentId,
            $categoryId,
            $this->outsideStaffId,
            "T070 outside $suffix",
            $now,
        );

        $this->visibleLearnerId = $this->insertLearner("t070-visible-$suffix", 'Visible Learner', true, $now);
        $this->privateLearnerId = $this->insertLearner("t070-private-$suffix", 'Secret Name', false, $now);
        $this->incompleteLearnerId = $this->insertLearner("t070-incomplete-$suffix", null, false, $now);
        $this->unauthorizedLearnerId = $this->insertLearner("t070-unauthorized-$suffix", null, false, $now);
        $this->outsideLearnerId = $this->insertLearner("t070-outside-learner-$suffix", 'Outside Learner', true, $now);

        foreach ([$this->visibleLearnerId, $this->privateLearnerId, $this->incompleteLearnerId] as $learnerId) {
            $this->insertEntitlement($learnerId, $this->courseId, $now);
        }
        $this->insertEntitlement($this->outsideLearnerId, $this->outsideCourseId, $now);
        $this->insertCompletedLesson($this->visibleLearnerId, $this->lessonId, $now);
        $this->insertCompletedLesson($this->privateLearnerId, $this->lessonId, $now);
        $this->insertCompletedLesson($this->outsideLearnerId, $this->outsideLessonId, $now);
    }

    private function insertDepartment(string $name, string $now): int
    {
        $id = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => $name,
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $id)->update(['path' => "/$id"]);
        return $id;
    }

    private function insertStaff(string $login, int $departmentId, string $now): int
    {
        $accountId = $this->insertAccount('staff', $login, $now);
        Db::name('staff_users')->insert([
            'account_id' => $accountId,
            'is_super_admin' => 0,
            'department_id' => $departmentId,
            'display_name' => $login,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    private function insertLearner(string $login, ?string $nickname, bool $showOnCourse, string $now): int
    {
        $accountId = $this->insertAccount('learner', $login, $now);
        Db::name('learners')->insert([
            'account_id' => $accountId,
            'nickname' => $nickname,
            'avatar_url' => null,
            'show_on_course' => $showOnCourse ? 1 : 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return $accountId;
    }

    private function insertAccount(string $kind, string $login, string $now): int
    {
        return (int) Db::name('accounts')->insertGetId([
            'kind' => $kind,
            'login' => $login,
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /** @return array{int, int} */
    private function insertCourseWithLesson(
        int $departmentId,
        int $categoryId,
        int $creatorId,
        string $title,
        string $now,
    ): array {
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => $title,
            'cover_url' => null,
            'teacher_name' => 'T070 teacher',
            'summary' => 'T070 integration fixture',
            'intro_rich_text' => '<p>T070</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $creatorId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $chapterId = (int) Db::name('chapters')->insertGetId([
            'course_id' => $courseId,
            'title' => "$title chapter",
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $lessonId = (int) Db::name('lessons')->insertGetId([
            'chapter_id' => $chapterId,
            'title' => "$title lesson",
            'sort' => 0,
            'status' => 'enabled',
            'content_type' => 'markdown',
            'body_markdown' => 'T070 lesson fixture',
            'asset_id' => null,
            'is_preview' => 0,
            'duration_seconds' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        return [$courseId, $lessonId];
    }

    private function insertEntitlement(int $learnerId, int $courseId, string $now): void
    {
        Db::name('course_entitlements')->insert([
            'learner_id' => $learnerId,
            'course_id' => $courseId,
            'source' => 'free',
            'order_id' => null,
            'status' => 'active',
            'revoked_at' => null,
            'revoked_reason' => null,
            'revoked_by_staff_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function insertCompletedLesson(int $learnerId, int $lessonId, string $now): void
    {
        Db::name('lesson_progresses')->insert([
            'learner_id' => $learnerId,
            'lesson_id' => $lessonId,
            'position_seconds' => 1,
            'opened_at' => $now,
            'completed' => 1,
            'completed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    private function assertBusinessException(
        callable $operation,
        string $apiCode,
        string $message,
    ): void {
        try {
            $operation();
            $this->fail("Expected $apiCode: $message");
        } catch (BusinessException $exception) {
            $this->assertSame($apiCode, $exception->apiCode);
            $this->assertSame($message, $exception->getMessage());
        }
    }

    private function callFeature(callable $operation): mixed
    {
        try {
            return $operation();
        } catch (\Error $error) {
            $this->fail('Missing T070 behavior: ' . $error->getMessage());
        }
    }
}
