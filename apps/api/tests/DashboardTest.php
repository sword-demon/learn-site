<?php
declare(strict_types=1);

namespace Tests;

use App\middleware\AdminAuth;
use App\middleware\Authorize;
use App\service\DashboardService;
use App\service\TokenService;
use PHPUnit\Framework\TestCase;

final class DashboardTest extends TestCase
{
    public function testSectionsOnlyExposeModulesTheStaffCanView(): void
    {
        $visible = DashboardService::visibleSections([
            'dashboard.view',
            'qa.view',
            'course.view',
        ]);

        $this->assertSame([
            'unanswered_questions' => true,
            'pending_reviews' => false,
            'abnormal_learning_maps' => false,
            'unpublished_courses' => true,
            'pending_orders' => false,
            'succeeded_orders' => false,
            'paid_amount' => false,
            'published_courses' => true,
            'recent_orders' => false,
            'order_trend' => false,
        ], $visible);
    }

    public function testSuperAdminCanSeeEveryDashboardSection(): void
    {
        $this->assertSame([
            'unanswered_questions' => true,
            'pending_reviews' => true,
            'abnormal_learning_maps' => true,
            'unpublished_courses' => true,
            'pending_orders' => true,
            'succeeded_orders' => true,
            'paid_amount' => true,
            'published_courses' => true,
            'recent_orders' => true,
            'order_trend' => true,
        ], DashboardService::visibleSections(['*']));
    }

    public function testRangeDaysAcceptsOnlySupportedDashboardWindows(): void
    {
        self::assertSame(7, DashboardService::normalizeRangeDays(7));
        self::assertSame(30, DashboardService::normalizeRangeDays(30));
        self::assertSame(90, DashboardService::normalizeRangeDays(90));
        self::assertSame(30, DashboardService::normalizeRangeDays(null));
        self::assertSame(30, DashboardService::normalizeRangeDays(14));
    }

    public function testAdminAuthRejectsLearnerTokenKind(): void
    {
        $learner = [
            'account_id' => '7',
            'kind' => TokenService::KIND_LEARNER,
            'family_id' => 'learner-family',
        ];
        $staff = [
            'account_id' => '8',
            'kind' => TokenService::KIND_STAFF,
            'family_id' => 'staff-family',
        ];

        $this->assertFalse(AdminAuth::acceptsTokenInfo($learner));
        $this->assertTrue(AdminAuth::acceptsTokenInfo($staff));
        $this->assertFalse(AdminAuth::acceptsTokenInfo(null));
    }

    public function testDashboardDestinationsUseSeededQaPermissions(): void
    {
        $this->assertSame('qa.view', Authorize::permissionFor('/api/admin/v1/questions', 'GET'));
        $this->assertSame('qa.answer', Authorize::permissionFor('/api/admin/v1/questions/3/answer', 'POST'));
        $this->assertNull(Authorize::permissionFor('/api/admin/v1/qa', 'GET'));
    }

    public function testEveryDashboardQueryAppliesCourseDataScope(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/service/DashboardService.php');

        $this->assertGreaterThanOrEqual(
            6,
            substr_count($source, '$this->applyScope('),
            'questions, reviews, maps, courses, orders and trends must all apply the same scope guard',
        );
        $this->assertStringContainsString("->where('m.status', 'published')", $source);
        $this->assertStringContainsString("->where('c.status', '<>', 'published')", $source);
        $this->assertStringContainsString("->whereIn('c.status', ['draft', 'unpublished'])", $source);
    }
}
