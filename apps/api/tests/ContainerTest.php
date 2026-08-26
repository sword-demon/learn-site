<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\AuthController;
use App\controller\learner\OrderController;
use App\middleware\AdminAuth;
use App\support\payment\PaymentAdapter;
use App\support\storage\ImageStorage;
use PHPUnit\Framework\TestCase;

final class ContainerTest extends TestCase
{
    public function testConfiguredContainerAutowiresControllerDependencies(): void
    {
        $container = require dirname(__DIR__) . '/config/container.php';

        $controller = $container->make(AuthController::class);

        self::assertInstanceOf(AuthController::class, $controller);
    }

    public function testConfiguredContainerAutowiresAndSharesMiddlewareDependencies(): void
    {
        $container = require dirname(__DIR__) . '/config/container.php';

        $middleware = $container->get(AdminAuth::class);

        self::assertInstanceOf(AdminAuth::class, $middleware);
        self::assertSame($middleware, $container->get(AdminAuth::class));
    }

    public function testConfiguredContainerResolvesInterfaceBindings(): void
    {
        $container = require dirname(__DIR__) . '/config/container.php';

        self::assertTrue($container->has(PaymentAdapter::class));
        self::assertTrue($container->has(ImageStorage::class));
        $controller = $container->make(OrderController::class);

        self::assertInstanceOf(OrderController::class, $controller);
    }
}
