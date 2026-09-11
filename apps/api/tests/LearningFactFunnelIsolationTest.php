<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class LearningFactFunnelIsolationTest extends TestCase
{
    public function testWritePathServicesDoNotReferenceTheFunnel(): void
    {
        $root = dirname(__DIR__) . '/app/service/';
        foreach (['ProgressService.php', 'EntitlementService.php', 'OrderService.php'] as $file) {
            $source = (string) file_get_contents($root . $file);
            self::assertStringNotContainsString('LearningFactFunnelService', $source, $file);
        }
    }
}
