<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\LearningMapController as LearnerLearningMapController;
use App\middleware\LearnerAuth;
use App\middleware\OptionalLearnerAuth;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class LearningMapRouteTest extends TestCase
{
    public function testLearnerRoutesExposeOptionalReadsAndAuthenticatedStart(): void
    {
        $this->assertTrue(class_exists(LearnerLearningMapController::class));
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }

        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = [
                    'callback' => $route->getCallback(),
                    'middleware' => $route->getMiddleware(),
                ];
            }
        }

        foreach (
            [
                'GET /api/learner/v1/learning-maps',
                'GET /api/learner/v1/learning-maps/{id}',
            ] as $route
        ) {
            $this->assertArrayHasKey($route, $routes);
            $this->assertContains(OptionalLearnerAuth::class, $routes[$route]['middleware']);
            $this->assertNotContains(LearnerAuth::class, $routes[$route]['middleware']);
            $this->assertSame(
                [
                    LearnerLearningMapController::class,
                    $route === 'GET /api/learner/v1/learning-maps' ? 'index' : 'show',
                ],
                $routes[$route]['callback'],
            );
        }

        $start = 'POST /api/learner/v1/learning-maps/{id}/start';
        $this->assertArrayHasKey($start, $routes);
        $this->assertContains(LearnerAuth::class, $routes[$start]['middleware']);
        $this->assertSame([LearnerLearningMapController::class, 'start'], $routes[$start]['callback']);
        $this->assertArrayNotHasKey('POST /api/learner/v1/learning-maps/{id}/enroll', $routes);
    }
}
