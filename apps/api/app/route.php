<?php

declare(strict_types=1);

use Webman\Route;

$learnerV1 = '/api/learner/v1';
$adminV1 = '/api/admin/v1';

Route::get('/health', [\App\controller\HealthController::class, 'health']);

Route::group($learnerV1, function () {
    Route::post('/auth/register', [\App\controller\learner\AuthController::class, 'register']);
    Route::post('/auth/login', [\App\controller\learner\AuthController::class, 'login']);
    Route::post('/auth/refresh', [\App\controller\learner\AuthController::class, 'refresh']);
    Route::get('/auth/captcha', [\App\controller\learner\AuthController::class, 'captcha']);
    Route::get('/home', [\App\controller\learner\HomeController::class, 'home']);

});

Route::group($learnerV1, function () {
    // Phase 5 / US1 — public catalog (auth optional)
    Route::get('/categories/{id}/courses', [\App\controller\learner\CatalogController::class, 'coursesByCategory']);
    Route::get('/courses/{id}', [\App\controller\learner\CatalogController::class, 'courseDetail']);
    Route::get('/courses/{courseId}/reviews', [\App\controller\learner\ReviewController::class, 'list']);
    Route::get('/reviews/{id}', [\App\controller\learner\ReviewController::class, 'thread']);
    Route::post('/courses/{id}/share-link', [\App\controller\learner\ShareController::class, 'link']);
    Route::post('/courses/{id}/posters', [\App\controller\learner\ShareController::class, 'poster']);

    // Phase 13 / US6 — published learning maps (auth optional)
    Route::get('/learning-maps', [\App\controller\learner\LearningMapController::class, 'index']);
    Route::get('/learning-maps/{id}', [\App\controller\learner\LearningMapController::class, 'show']);
})->middleware([\App\middleware\OptionalLearnerAuth::class]);

Route::group($learnerV1, function () {
    Route::post('/auth/logout', [\App\controller\learner\AuthController::class, 'logout']);
    Route::get('/me', [\App\controller\learner\LearnerController::class, 'me']);
    Route::patch('/me', [\App\controller\learner\LearnerController::class, 'updateMe']);

    // Phase 5 / US1 — lesson delivery (auth required)
    Route::get('/courses/{courseId}/lessons/{lessonId}', [\App\controller\learner\LessonController::class, 'deliver']);

    // Phase 6 / US3 — acquisition, progress, resume (auth required)
    Route::post('/courses/{id}/start',       [\App\controller\learner\LearningController::class, 'start']);
    Route::post('/courses/{id}/orders',      [\App\controller\learner\OrderController::class, 'create']);
    Route::get('/orders',                    [\App\controller\learner\OrderController::class, 'index']);
    Route::get('/orders/{id}',               [\App\controller\learner\OrderController::class, 'show']);
    Route::get('/my/learning',               [\App\controller\learner\LearningController::class, 'myLearning']);
    Route::post('/lessons/{id}/progress',    [\App\controller\learner\LearningController::class, 'reportProgress']);
    Route::post('/lessons/{id}/video-heartbeat', [\App\controller\learner\LearningController::class, 'videoHeartbeat']);

    // Phase 11 / US4 — lesson Q&A (auth required)
    Route::get('/lessons/{lessonId}/questions',       [\App\controller\learner\QuestionController::class, 'index']);
    Route::post('/lessons/{lessonId}/questions',      [\App\controller\learner\QuestionController::class, 'create']);
    Route::get('/questions/{id}',                     [\App\controller\learner\QuestionController::class, 'show']);
    Route::post('/questions/{id}/messages',           [\App\controller\learner\QuestionController::class, 'followup']);

    // Phase 12 / US5 — reviews + reply tree
    Route::post('/courses/{courseId}/reviews', [\App\controller\learner\ReviewController::class, 'post']);
    Route::patch('/reviews/{id}', [\App\controller\learner\ReviewController::class, 'update']);
    Route::delete('/reviews/{id}', [\App\controller\learner\ReviewController::class, 'destroy']);
    Route::post('/reviews/{id}/replies', [\App\controller\learner\ReviewController::class, 'reply']);

    // Phase 13 / US6 — learning maps
    Route::post('/learning-maps/{id}/start', [\App\controller\learner\LearningMapController::class, 'start']);

    // Phase 17 / US7 — favorites
    Route::get('/me/favorites', [\App\controller\learner\FavoriteController::class, 'index']);
    Route::post('/courses/{id}/favorite', [\App\controller\learner\FavoriteController::class, 'add']);
    Route::delete('/courses/{id}/favorite', [\App\controller\learner\FavoriteController::class, 'remove']);

    // Phase 21 / US18 — learner inbox (T104 surface)
    Route::get('/messages', [\App\controller\learner\NotificationController::class, 'index']);
    Route::get('/messages/unread-count', [\App\controller\learner\NotificationController::class, 'unreadCount']);
    Route::post('/messages/{id}/read', [\App\controller\learner\NotificationController::class, 'read']);
})->middleware([\App\middleware\LearnerAuth::class]);

// The fake notify seam is test-only. Production settles through the delayed
// FakePaymentAdapter callback and must not expose a caller-controlled status
// transition route. A future real provider will add a signed callback here.
if (getenv('APP_ENV') === 'testing') {
    Route::group('/api/internal/v1', function () {
        Route::post('/payments/fake/notify', [\App\controller\internal\PaymentNotifyController::class, 'fake']);
    });
}

Route::get('/api/media/assets/{id}', [\App\controller\media\CourseMediaController::class, 'show'])
    ->middleware([\App\middleware\OptionalLearnerAuth::class]);
Route::get('/api/media/{key:.+}', [\App\controller\media\CourseCoverMediaController::class, 'show']);

Route::group($adminV1, function () {
    Route::post('/auth/login', [\App\controller\admin\AuthController::class, 'login']);
    Route::post('/auth/refresh', [\App\controller\admin\AuthController::class, 'refresh']);
    Route::get('/auth/captcha', [\App\controller\admin\AuthController::class, 'captcha']);
});

Route::group($adminV1, function () {
    Route::post('/auth/logout', [\App\controller\admin\AuthController::class, 'logout']);
    Route::post('/auth/password/first', [\App\controller\admin\AuthController::class, 'firstPassword']);
    Route::post('/learners/{id}/kick', [\App\controller\admin\LearnerController::class, 'kickLearner']);
    Route::post('/learners/{id}/password', [\App\controller\admin\LearnerController::class, 'resetPassword']);
})->middleware([
    \App\middleware\AdminAuth::class,
    \App\middleware\Authorize::class,
]);

// Catalog (Phase 4 / US2). The Authorize middleware enforces category.manage /
// course.view / course.manage / course.publish / course.delete by path prefix.
Route::group($adminV1, function () {
    // Phase 8 / US12 — org & RBAC. Authorize forces org.department / org.post /
    // org.role / org.staff / org.grant by prefix.
    Route::get('/departments',                  [\App\controller\admin\DepartmentController::class, 'index']);
    Route::post('/departments',                 [\App\controller\admin\DepartmentController::class, 'create']);
    Route::patch('/departments/{id}',           [\App\controller\admin\DepartmentController::class, 'update']);
    Route::patch('/departments/{id}/status',    [\App\controller\admin\DepartmentController::class, 'status']);
    Route::delete('/departments/{id}',          [\App\controller\admin\DepartmentController::class, 'destroy']);

    Route::get('/posts',                        [\App\controller\admin\PostController::class, 'index']);
    Route::post('/posts',                       [\App\controller\admin\PostController::class, 'create']);
    Route::patch('/posts/{id}',                 [\App\controller\admin\PostController::class, 'update']);
    Route::delete('/posts/{id}',                [\App\controller\admin\PostController::class, 'destroy']);

    Route::get('/roles',                        [\App\controller\admin\RoleController::class, 'index']);
    Route::post('/roles',                       [\App\controller\admin\RoleController::class, 'create']);
    Route::patch('/roles/{id}',                 [\App\controller\admin\RoleController::class, 'update']);
    Route::patch('/roles/{id}/status',          [\App\controller\admin\RoleController::class, 'status']);
    Route::delete('/roles/{id}',                [\App\controller\admin\RoleController::class, 'destroy']);
    Route::get('/permissions',                  [\App\controller\admin\RoleController::class, 'permissions']);

    Route::get('/staff',                        [\App\controller\admin\StaffController::class, 'index']);
    Route::get('/staff/{id}',                   [\App\controller\admin\StaffController::class, 'show']);
    Route::post('/staff',                       [\App\controller\admin\StaffController::class, 'create']);
    Route::patch('/staff/{id}',                 [\App\controller\admin\StaffController::class, 'update']);
    Route::patch('/staff/{id}/status',          [\App\controller\admin\StaffController::class, 'status']);
    Route::delete('/staff/{id}',                [\App\controller\admin\StaffController::class, 'destroy']);
    Route::put('/staff/{id}/overrides',         [\App\controller\admin\StaffController::class, 'overrides']);
    Route::post('/staff/{id}/kick',             [\App\controller\admin\StaffController::class, 'kick']);

    // Categories
    Route::get('/categories', [\App\controller\admin\CategoryController::class, 'index']);
    Route::get('/categories/flat', [\App\controller\admin\CategoryController::class, 'flat']);
    Route::post('/categories', [\App\controller\admin\CategoryController::class, 'create']);
    Route::patch('/categories/{id}', [\App\controller\admin\CategoryController::class, 'update']);
    Route::patch('/categories/{id}/status', [\App\controller\admin\CategoryController::class, 'status']);
    Route::delete('/categories/{id}', [\App\controller\admin\CategoryController::class, 'destroy']);

    // Courses + nested chapters + lessons
    Route::get('/courses', [\App\controller\admin\CourseController::class, 'index']);
    Route::post('/courses', [\App\controller\admin\CourseController::class, 'create']);
    Route::get('/courses/{id}', [\App\controller\admin\CourseController::class, 'show']);
    Route::patch('/courses/{id}', [\App\controller\admin\CourseController::class, 'update']);
    Route::post('/courses/{id}/publish', [\App\controller\admin\CourseController::class, 'publish']);
    Route::post('/courses/{id}/unpublish', [\App\controller\admin\CourseController::class, 'unpublish']);
    Route::delete('/courses/{id}', [\App\controller\admin\CourseController::class, 'destroy']);

    Route::get('/courses/{id}/chapters', [\App\controller\admin\CourseController::class, 'listChapters']);
    Route::post('/courses/{id}/chapters', [\App\controller\admin\CourseController::class, 'createChapter']);
    Route::patch('/courses/{id}/chapters/{chapterId}', [\App\controller\admin\CourseController::class, 'updateChapter']);
    Route::delete('/courses/{id}/chapters/{chapterId}', [\App\controller\admin\CourseController::class, 'deleteChapter']);

    Route::get('/courses/{id}/lessons', [\App\controller\admin\CourseController::class, 'listLessons']);
    Route::post('/courses/{id}/lessons', [\App\controller\admin\CourseController::class, 'createLesson']);
    Route::patch('/courses/{id}/lessons/{lessonId}', [\App\controller\admin\CourseController::class, 'updateLesson']);
    Route::delete('/courses/{id}/lessons/{lessonId}', [\App\controller\admin\CourseController::class, 'deleteLesson']);

    // Assets
    Route::post('/assets', [\App\controller\admin\AssetController::class, 'upload']);
    Route::post('/course-covers', [\App\controller\admin\CourseCoverController::class, 'upload']);
    Route::post('/map-covers', [\App\controller\admin\CourseCoverController::class, 'upload']);

    // Phase 11 / US4 — Q&A admin inbox (qa.view / qa.answer)
    Route::get('/questions',           [\App\controller\admin\QuestionController::class, 'inbox']);
    Route::get('/questions/filter-options', [\App\controller\admin\QuestionController::class, 'filterOptions']);
    Route::get('/questions/{id}',      [\App\controller\admin\QuestionController::class, 'show']);
    Route::post('/questions/{id}/answer', [\App\controller\admin\QuestionController::class, 'answer']);
    Route::post('/questions/{id}/close', [\App\controller\admin\QuestionController::class, 'close']);

    // Phase 12 / US5 — review moderation (review.view / review.moderate)
    Route::get('/reviews',             [\App\controller\admin\ReviewController::class, 'list']);
    Route::get('/reviews/filter-options', [\App\controller\admin\ReviewController::class, 'filterOptions']);
    Route::get('/reviews/{id}',        [\App\controller\admin\ReviewController::class, 'show']);
    Route::post('/reviews/{id}/hide',  [\App\controller\admin\ReviewController::class, 'hide']);
    Route::post('/reviews/{id}/restore', [\App\controller\admin\ReviewController::class, 'restore']);
    Route::post('/reviews/{id}/replies', [\App\controller\admin\ReviewController::class, 'reply']);
    Route::post('/review-replies/{id}/hide', [\App\controller\admin\ReviewController::class, 'hideReply']);
    Route::post('/review-replies/{id}/restore', [\App\controller\admin\ReviewController::class, 'restoreReply']);

    // Phase 13 / US6 — learning-map editor (map.view / map.manage)
    Route::get('/learning-maps', [\App\controller\admin\LearningMapController::class, 'index']);
    Route::post('/learning-maps', [\App\controller\admin\LearningMapController::class, 'create']);
    Route::get('/learning-maps/{id}', [\App\controller\admin\LearningMapController::class, 'show']);
    Route::patch('/learning-maps/{id}', [\App\controller\admin\LearningMapController::class, 'update']);
    Route::delete('/learning-maps/{id}', [\App\controller\admin\LearningMapController::class, 'destroy']);
    Route::post('/learning-maps/{id}/publish', [\App\controller\admin\LearningMapController::class, 'publish']);
    Route::post('/learning-maps/{id}/unpublish', [\App\controller\admin\LearningMapController::class, 'unpublish']);
    Route::post('/learning-maps/{id}/stages', [\App\controller\admin\LearningMapController::class, 'addStage']);
    Route::patch('/learning-maps/{id}/stages/{stageId}', [\App\controller\admin\LearningMapController::class, 'updateStage']);
    Route::delete('/learning-maps/{id}/stages/{stageId}', [\App\controller\admin\LearningMapController::class, 'deleteStage']);
    Route::post('/learning-maps/{id}/stages/{stageId}/courses', [\App\controller\admin\LearningMapController::class, 'addCourseToStage']);
    Route::delete('/learning-maps/{id}/stages/{stageId}/courses/{courseId}', [\App\controller\admin\LearningMapController::class, 'removeCourseFromStage']);

    // Phase 14 / US10 — admin orders, read-only (order.view)
    // T079: intentionally no POST/PATCH/DELETE handlers — payment state
    // changes flow through PaymentAdapter::onNotify() exclusively.
    Route::get('/orders',      [\App\controller\admin\OrderController::class, 'index']);
    Route::get('/orders/{id}', [\App\controller\admin\OrderController::class, 'show']);

    // Phase 18 / US8 — site-wide learner accounts + per-course student list (T091).
    Route::get('/learners', [\App\controller\admin\LearnerController::class, 'index']);
    Route::get('/courses/{courseId}/students', [\App\controller\admin\CourseStudentController::class, 'index']);
    Route::post('/courses/{courseId}/students/{accountId}/progress/reset', [\App\controller\admin\CourseStudentController::class, 'resetProgress']);
    Route::post('/courses/{courseId}/students/{accountId}/revoke', [\App\controller\admin\CourseStudentController::class, 'revoke']);
})->middleware([
    \App\middleware\AdminAuth::class,
    \App\middleware\Authorize::class,
]);

// Dashboard (Phase 7 / US9). Authorize enforces dashboard.view; AdminAuth
// already rejects learner tokens via TokenService::KIND_STAFF guard.
Route::group($adminV1, function () {
    Route::get('/dashboard', [\App\controller\admin\DashboardController::class, 'summary']);
})->middleware([
    \App\middleware\AdminAuth::class,
    \App\middleware\Authorize::class,
]);

// Phase 19 / US11 — site profile + moderation log.
Route::group($adminV1, function () {
    Route::get('/site', [\App\controller\admin\SiteController::class, 'show']);
    Route::patch('/site', [\App\controller\admin\SiteController::class, 'update']);
    Route::get('/moderation-logs', [\App\controller\admin\AuditController::class, 'index']);

    // 003-admin-notifications — dispatch announcements and internal messages
    Route::get('/notifications', [\App\controller\admin\NotificationController::class, 'index']);
    Route::get('/notifications/{id}', [\App\controller\admin\NotificationController::class, 'show']);
    Route::post('/notifications/announcements', [\App\controller\admin\NotificationController::class, 'storeAnnouncement']);
    Route::post('/notifications/internal-messages', [\App\controller\admin\NotificationController::class, 'storeInternalMessage']);
})->middleware([
    \App\middleware\AdminAuth::class,
    \App\middleware\Authorize::class,
]);
