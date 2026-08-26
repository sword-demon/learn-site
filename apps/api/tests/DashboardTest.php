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
            'recent_orders' => false,
        ], $visible);
    }

    public function testSuperAdminCanSeeEveryDashboardSection(): void
    {
        $this->assertSame([
            'unanswered_questions' => true,
            'pending_reviews' => true,
            'abnormal_learning_maps' => true,
            'unpublished_courses' => true,
            'recent_orders' => true,
        ], DashboardService::visibleSections(['*']));
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
        $this->assertSame('qa.view', Authorize::permissionFor('/api/admin/v1/qa', 'GET'));
        $this->assertSame('qa.answer', Authorize::permissionFor('/api/admin/v1/qa/3/answer', 'POST'));
    }

    public function testEveryDashboardQueryAppliesCourseDataScope(): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/app/service/DashboardService.php');

        $this->assertSame(
            5,
            substr_count($source, '$this->applyScope('),
            'questions, reviews, maps, courses and orders must all apply the same scope guard',
        );
        $this->assertStringContainsString("->where('m.status', 'published')", $source);
        $this->assertStringContainsString("->where('c.status', '<>', 'published')", $source);
        $this->assertStringContainsString("->whereIn('c.status', ['draft', 'unpublished'])", $source);
    }
}
