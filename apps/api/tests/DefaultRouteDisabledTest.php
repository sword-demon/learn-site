<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\DistributionController;
use App\controller\public\ShareLandingController;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class DefaultRouteDisabledTest extends TestCase
{
    /** @var array<string, array{callback: mixed, middleware: mixed}> */
    private array $routes = [];

    protected function setUp(): void
    {
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
        $this->routes = $routes;
    }

    public function testDefaultRouteIsDisabledForTheMainApp(): void
    {
        self::assertTrue(
            Route::isDefaultRouteDisabled(''),
            'API-only app must call Route::disableDefaultRoute() so /admin/content-todo/index cannot skip AdminAuth',
        );
    }

    public function testShareLandingHasASinglePublicApiEntry(): void
    {
        self::assertArrayHasKey('GET /api/public/s/{code}', $this->routes);
        self::assertSame(
            [ShareLandingController::class, 'show'],
            $this->routes['GET /api/public/s/{code}']['callback'],
        );
        self::assertArrayNotHasKey('GET /api/learner/v1/r/{code}', $this->routes);
    }

    public function testLearnerShareCrudHasASingleAuthenticatedEntry(): void
    {
        self::assertArrayHasKey('GET /api/learner/v1/distribution/share-entries', $this->routes);
        self::assertSame(
            [DistributionController::class, 'shareEntries'],
            $this->routes['GET /api/learner/v1/distribution/share-entries']['callback'],
        );
        self::assertArrayNotHasKey('GET /api/learner/v1/me/share-entries', $this->routes);
        self::assertArrayNotHasKey('POST /api/learner/v1/me/share-entries', $this->routes);
        self::assertArrayNotHasKey('DELETE /api/learner/v1/me/share-entries/{id}', $this->routes);
    }
}
