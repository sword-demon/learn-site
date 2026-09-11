<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\ContentTodoController;
use App\middleware\AdminAuth;
use App\middleware\Authorize;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class ContentTodoRouteTest extends TestCase
{
    public function testContentTodoRoutesAreRegisteredWithAdminAuthAndAuthorize(): void
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

        $expected = [
            'GET /api/admin/v1/ops-inbox/content-todos' => [ContentTodoController::class, 'index'],
            'GET /api/admin/v1/ops-inbox/content-todos/{id}' => [ContentTodoController::class, 'show'],
            'PATCH /api/admin/v1/ops-inbox/content-todos/{id}' => [ContentTodoController::class, 'patch'],
            'POST /api/admin/v1/ops-inbox/content-todos/{id}/respond' => [ContentTodoController::class, 'respond'],
            'POST /api/admin/v1/ops-inbox/content-todos/{id}/candidates' => [ContentTodoController::class, 'generateCandidate'],
            'PATCH /api/admin/v1/ops-inbox/content-todos/{id}/candidates/{candidateId}' => [ContentTodoController::class, 'editCandidate'],
            'POST /api/admin/v1/ops-inbox/content-todos/{id}/candidates/{candidateId}/approve' => [ContentTodoController::class, 'approveCandidate'],
            'POST /api/admin/v1/ops-inbox/content-todos/{id}/candidates/{candidateId}/reject' => [ContentTodoController::class, 'rejectCandidate'],
            'POST /api/admin/v1/ops-inbox/content-todos/{id}/close' => [ContentTodoController::class, 'close'],
        ];

        foreach ($expected as $key => $callback) {
            self::assertArrayHasKey($key, $routes);
            self::assertSame($callback, $routes[$key]['callback']);
            self::assertContains(AdminAuth::class, $routes[$key]['middleware']);
            self::assertContains(Authorize::class, $routes[$key]['middleware']);
        }
    }
}
