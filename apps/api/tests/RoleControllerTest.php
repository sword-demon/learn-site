<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\RoleController;
use App\service\PermissionService;
use App\service\RoleService;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use Webman\ThinkOrm\ThinkOrm;

final class RoleControllerTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    public function testPermissionCatalogueUsesChineseDescriptions(): void
    {
        $request = new Request(
            "GET /api/admin/v1/permissions HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        $response = (new RoleController(new RoleService(new PermissionService())))->permissions($request);
        $payload = json_decode((string) $response->rawBody(), true);
        $items = is_array($payload['data']['items'] ?? null) ? $payload['data']['items'] : [];
        $byCode = [];
        foreach ($items as $item) {
            if (is_array($item) && isset($item['code'])) {
                $byCode[(string) $item['code']] = $item;
            }
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('查看管理工作台', $byCode['dashboard.view']['description'] ?? null);
        self::assertSame('编辑课程内容', $byCode['course.manage']['description'] ?? null);
        self::assertSame('管理角色', $byCode['org.role']['description'] ?? null);
    }
}
