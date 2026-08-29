<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\AuthController;
use App\controller\learner\OrderController;
use App\middleware\AdminAuth;
use App\support\payment\PaymentAdapter;
use App\support\storage\ImageStorage;
use PHPUnit\Framework\TestCase;
use support\App;

final class ContainerTest extends TestCase
{
    public function testConfiguredProcessHandlersExist(): void
    {
        App::loadAllConfig(['route', 'container']);
        $processes = config('process');
        self::assertIsArray($processes);

        foreach ($processes as $name => $process) {
            self::assertIsArray($process);
            self::assertArrayHasKey('handler', $process);
            self::assertTrue(
                class_exists($process['handler']),
                sprintf('Configured process %s handler %s must exist', $name, $process['handler']),
            );
        }
    }

    public function testWorkermanUsesForegroundContainerLogging(): void
    {
        $server = require dirname(__DIR__) . '/config/server.php';

        self::assertSame('php://stdout', $server['stdout_file']);
        self::assertSame('/dev/null', $server['log_file']);
    }

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
