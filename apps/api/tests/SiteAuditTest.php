<?php

declare(strict_types=1);

namespace Tests;

use App\middleware\Authorize;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\HomeService;
use App\service\ModerationLogService;
use App\service\ReviewService;
use App\service\SiteService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\think\Db;
use Webman\Route;
use Webman\ThinkOrm\ThinkOrm;

final class SiteAuditTest extends TestCase
{
    private int $staffId;
    private string $staffLogin;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $this->staffLogin = "site-admin-$suffix";
        $this->staffId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'staff',
            'login' => $this->staffLogin,
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('staff_users')->insert([
            'account_id' => $this->staffId,
            'is_super_admin' => 1,
            'department_id' => null,
            'display_name' => 'Site Admin',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testSavedSiteProfileIsSanitizedAndImmediatelyVisibleToThePublicHome(): void
    {
        $saved = (new SiteService())->update($this->staffId, [
            'title' => '林间课室',
            'subtitle' => '持续学习，持续记录',
            'body_html' => '<p>公开介绍</p><script>alert(1)</script>',
            'contact_email' => 'hello@example.test',
        ]);

        self::assertSame('林间课室', $saved['title']);
        self::assertStringContainsString('<p>公开介绍</p>', $saved['body_html']);
        self::assertStringNotContainsString('<script', $saved['body_html']);
        self::assertSame($saved, (new HomeService())->siteIntro());
        self::assertSame(1, (int) Db::name('site_profile')->count());
    }

    public function testModerationHistoryContainsReasonOperatorAndRestoresTheHiddenReview(): void
    {
        $reviewId = $this->insertPublicReview();
        $reviews = new ReviewService(new EntitlementService(), new DataScopeService());
        $reviews->hideReview($this->staffId, $reviewId, '误含广告链接');

        $logs = (new ModerationLogService(new DataScopeService()))->list(
            $this->staffId,
            'review',
            'hide',
            null,
            1,
            20,
        );

        self::assertSame(1, $logs['total']);
        self::assertSame(20, $logs['limit']);
        self::assertArrayNotHasKey('page_size', $logs);
        self::assertSame('review', $logs['items'][0]['object_type']);
        self::assertSame($reviewId, $logs['items'][0]['object_id']);
        self::assertSame('误含广告链接', $logs['items'][0]['reason']);
        self::assertSame($this->staffLogin, $logs['items'][0]['staff_login']);
        self::assertTrue($logs['items'][0]['restorable']);

        $reviews->restoreReview($this->staffId, $reviewId);
        $afterRestore = (new ModerationLogService(new DataScopeService()))->list(
            $this->staffId,
            'review',
            null,
            null,
            1,
            20,
        );

        self::assertSame(2, $afterRestore['total']);
        self::assertSame('restore', $afterRestore['items'][0]['action']);
        self::assertFalse($afterRestore['items'][0]['restorable']);
        self::assertSame('public', Db::name('reviews')->where('id', $reviewId)->value('visibility'));
    }

    public function testOnlyTheContractedModerationRouteUsesAuditPermission(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $paths = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $paths[] = "$method {$route->getPath()}";
            }
        }

        self::assertContains('GET /api/admin/v1/moderation-logs', $paths);
        self::assertNotContains('GET /api/admin/v1/audit', $paths);
        self::assertSame(
            'audit.view',
            Authorize::permissionFor('/api/admin/v1/moderation-logs', 'GET'),
        );
        self::assertNull(Authorize::permissionFor('/api/admin/v1/audit', 'GET'));
    }

    private function insertPublicReview(): int
    {
        $suffix = bin2hex(random_bytes(6));
        $now = date('Y-m-d H:i:s');
        $departmentId = (int) Db::name('departments')->insertGetId([
            'parent_id' => null,
            'name' => "Site department $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('departments')->where('id', $departmentId)->update(['path' => "/$departmentId"]);
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => "Site category $suffix",
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => $departmentId,
            'category_id' => $categoryId,
            'title' => "Site course $suffix",
            'cover_url' => null,
            'teacher_name' => 'Site teacher',
            'summary' => 'Site audit fixture',
            'intro_rich_text' => '<p>Site audit fixture</p>',
            'status' => 'published',
            'price_mode' => 'free',
            'list_price' => 0,
            'sale_price' => 0,
            'sale_start_at' => null,
            'sale_end_at' => null,
            'created_by_staff_id' => $this->staffId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $learnerId = (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(10000000, 99999999),
            'password_hash' => 'not-used-by-test',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        Db::name('learners')->insert([
            'account_id' => $learnerId,
            'nickname' => '公开学员',
            'avatar_url' => null,
            'show_on_course' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return (int) Db::name('reviews')->insertGetId([
            'course_id' => $courseId,
            'learner_id' => $learnerId,
            'rating' => 5,
            'body' => '待审核评价',
            'visibility' => 'public',
            'active_key' => "$learnerId:$courseId",
            'hidden_reason' => null,
            'hidden_by_staff_id' => null,
            'hidden_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
