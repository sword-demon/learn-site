<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\OrderController;
use App\controller\internal\PaymentNotifyController;
use App\middleware\AdminAuth;
use App\middleware\Authorize;
use PHPUnit\Framework\TestCase;
use Webman\Route;

final class OrderReadOnlyRouteTest extends TestCase
{
    public function testAdminOrderRoutesAreReadOnlyAndPaymentNotifyRemainsInternal(): void
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

        $expectedAdminRoutes = [
            'GET /api/admin/v1/orders' => [OrderController::class, 'index'],
            'GET /api/admin/v1/orders/{id}' => [OrderController::class, 'show'],
        ];

        foreach ($expectedAdminRoutes as $key => $callback) {
            self::assertArrayHasKey($key, $routes);
            self::assertSame($callback, $routes[$key]['callback']);
            self::assertContains(AdminAuth::class, $routes[$key]['middleware']);
            self::assertContains(Authorize::class, $routes[$key]['middleware']);
        }

        $registeredAdminOrderRoutes = array_filter(
            array_keys($routes),
            static fn(string $key): bool => str_contains($key, ' /api/admin/v1/orders'),
        );
        sort($registeredAdminOrderRoutes);
        $expectedKeys = array_keys($expectedAdminRoutes);
        sort($expectedKeys);
        self::assertSame($expectedKeys, $registeredAdminOrderRoutes);

        $notifyKey = 'POST /api/internal/v1/payments/fake/notify';
        self::assertArrayHasKey($notifyKey, $routes);
        self::assertSame(
            [PaymentNotifyController::class, 'fake'],
            $routes[$notifyKey]['callback'],
        );
        self::assertNotContains(AdminAuth::class, $routes[$notifyKey]['middleware']);
        self::assertNotContains(Authorize::class, $routes[$notifyKey]['middleware']);
    }
}
