<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\LearningFactFunnelController;
use App\middleware\AdminAuth;
use App\middleware\Authorize;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class LearningFactFunnelRouteTest extends TestCase
{
    public function testLearningFunnelRouteUsesCourseStudentViewGuards(): void
    {
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $key = 'GET /api/admin/v1/courses/{id}/learning-funnel';
        $found = null;
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                if ("$method {$route->getPath()}" === $key) {
                    $found = $route;
                    break 2;
                }
            }
        }
        self::assertNotNull($found);
        self::assertSame([LearningFactFunnelController::class, 'show'], $found->getCallback());
        self::assertContains(AdminAuth::class, $found->getMiddleware());
        self::assertContains(Authorize::class, $found->getMiddleware());
    }
}
