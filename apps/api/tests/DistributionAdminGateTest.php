<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\DistributionController;
use App\service\CommissionService;
use App\service\DistributionConfigService;
use App\service\DistributionCourseOverrideService;
use PHPUnit\Framework\TestCase;
use support\Request;

final class DistributionAdminGateTest extends TestCase
{
    public function testGetConfigWithoutStaffIdReturnsUnauthenticated(): void
    {
        $controller = new DistributionController(
            new DistributionConfigService(),
            new DistributionCourseOverrideService(),
            new CommissionService(),
        );
        $request = new Request("GET /api/admin/v1/distribution/config HTTP/1.1\r\nHost: test\r\n\r\n");
        $response = $controller->getConfig($request);
        self::assertSame(401, $response->getStatusCode());
        $body = json_decode((string) $response->rawBody(), true);
        self::assertIsArray($body);
        self::assertSame('UNAUTHENTICATED', $body['error']['code'] ?? null);
    }
}
