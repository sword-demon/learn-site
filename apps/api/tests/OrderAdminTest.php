<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\OrderController;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\OrderService;
use App\support\payment\FakePaymentAdapter;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Context;
use support\Request;
use Webman\ThinkOrm\ThinkOrm;

final class OrderAdminTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function tearDown(): void
    {
        Context::reset();
    }

    public function testListRejectsNonIntegerCourseFilter(): void
    {
        $request = new Request(
            "GET /api/admin/v1/orders?course_id=1.2 HTTP/1.1\r\nHost: test\r\n\r\n",
        );
        $request->account_id = 1;
        Context::reset(new ArrayObject([\Webman\Http\Request::class => $request]));

        $response = $this->controller()->index($request);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(400, $response->getStatusCode());
        self::assertIsArray($payload);
        self::assertSame('VALIDATION_FAILED', $payload['error']['code'] ?? null);
        self::assertSame('INVALID_ID', $payload['error']['message'] ?? null);
    }

    private function controller(): OrderController
    {
        return new OrderController(
            new OrderService(new EntitlementService(), new FakePaymentAdapter()),
            new DataScopeService(),
        );
    }
}
