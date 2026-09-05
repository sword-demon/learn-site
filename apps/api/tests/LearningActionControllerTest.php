<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\LearningActionController;
use App\middleware\LearnerAuth;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use Webman\Route;

final class LearningActionControllerTest extends TestCase
{
    public function testUnauthenticatedRequestIsRejected(): void
    {
        $request = new Request("GET /api/learner/v1/me/next-action HTTP/1.1\r\nHost: test\r\n\r\n");
        $response = (new LearningActionController())->index($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testRouteIsLearnerProtectedAndResponseIsPrivate(): void
    {
        App::loadAllConfig(['route', 'container']);
        if (Route::getRoutes() === []) {
            Route::load([app_path()]);
        }
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            foreach ($route->getMethods() as $method) {
                $routes["$method {$route->getPath()}"] = $route->getMiddleware();
            }
        }

        self::assertContains(LearnerAuth::class, $routes['GET /api/learner/v1/me/next-action'] ?? []);
        $request = new Request("GET /api/learner/v1/me/next-action HTTP/1.1\r\nHost: test\r\n\r\n");
        $request->account_id = 0;
        $response = (new LearningActionController())->index($request);
        self::assertSame('private, no-store', $response->getHeader('Cache-Control'));
    }
}
