<?php

declare(strict_types=1);

namespace Tests;

use App\controller\admin\OrderController;
use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\EntitlementService;
use App\service\OrderService;
use App\support\payment\FakePaymentAdapter;
use ArrayObject;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Context;
use support\Request;
use support\think\Db;
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

    public function testAdminListCanQueryOrdersWithAnAliasedTable(): void
    {
        $result = (new OrderService(
            new EntitlementService(),
            new FakePaymentAdapter(),
        ))->adminList(1, null, null, null, 1, 20, new DataScopeService());

        self::assertArrayHasKey('items', $result);
        self::assertSame(1, $result['page']);
    }

    public function testAdminShowReturnsNotFoundAfterQueryingTheAliasedOrdersTable(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('ORDER_NOT_FOUND');

        (new OrderService(
            new EntitlementService(),
            new FakePaymentAdapter(),
        ))->adminShow(1, PHP_INT_MAX, new DataScopeService());
    }

    public function testAdminListReturnsOrderStatusNotCourseStatus(): void
    {
        Db::startTrans();
        try {
            $now = '2026-08-20 10:00:00';
            $staffId = (int) Db::name('accounts')->insertGetId([
                'kind' => 'staff',
                'login' => 'order-admin-' . bin2hex(random_bytes(4)),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('staff_users')->insert([
                'account_id' => $staffId,
                'is_super_admin' => 1,
                'department_id' => null,
                'display_name' => 'Order Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $learnerId = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . bin2hex(random_bytes(4)),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $categoryId = (int) Db::name('categories')->insertGetId([
                'parent_id' => 0,
                'name' => 'order-admin-cat-' . bin2hex(random_bytes(3)),
                'path' => '/',
                'depth' => 1,
                'sort' => 0,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $courseId = (int) Db::name('courses')->insertGetId([
                'department_id' => null,
                'category_id' => $categoryId,
                'title' => '已发布课程',
                'cover_url' => null,
                'teacher_name' => '讲师',
                'summary' => null,
                'intro_rich_text' => null,
                'status' => 'published',
                'price_mode' => 'paid',
                'list_price' => 199,
                'sale_price' => 149,
                'sale_start_at' => $now,
                'sale_end_at' => '2026-08-30 10:00:00',
                'created_by_staff_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) Db::name('orders')->insertGetId([
                'learner_id' => $learnerId,
                'course_id' => $courseId,
                'learner_coupon_id' => null,
                'list_price_snapshot' => 199,
                'sale_price_snapshot' => 149,
                'coupon_discount_snapshot' => 0,
                'paid_amount' => 149,
                'currency' => 'CNY',
                'status' => 'succeeded',
                'provider' => 'fake',
                'provider_ref' => 'ref-admin-list',
                'succeeded_at' => '2026-08-20 10:01:00',
                'created_at' => $now,
                'updated_at' => '2026-08-20 10:01:00',
            ]);

            $result = (new OrderService(
                new EntitlementService(),
                new FakePaymentAdapter(),
            ))->adminList($staffId, null, $courseId, null, 1, 20, new DataScopeService());

            self::assertNotEmpty($result['items']);
            $item = array_values(array_filter(
                $result['items'],
                static fn(array $row): bool => (int) $row['order_id'] === $orderId,
            ))[0] ?? null;
            self::assertIsArray($item);
            self::assertSame('succeeded', $item['status']);
            self::assertSame('已发布课程', $item['course_title']);
        } finally {
            Db::rollback();
        }
    }

    public function testAdminShowReturnsOrderStatusNotCourseStatus(): void
    {
        Db::startTrans();
        try {
            $now = '2026-08-20 10:00:00';
            $staffId = (int) Db::name('accounts')->insertGetId([
                'kind' => 'staff',
                'login' => 'order-admin-show-' . bin2hex(random_bytes(4)),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            Db::name('staff_users')->insert([
                'account_id' => $staffId,
                'is_super_admin' => 1,
                'department_id' => null,
                'display_name' => 'Order Admin',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $learnerId = (int) Db::name('accounts')->insertGetId([
                'kind' => 'learner',
                'login' => '13' . bin2hex(random_bytes(4)),
                'password_hash' => 'x',
                'must_change_password' => 0,
                'status' => 'active',
                'last_login_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $categoryId = (int) Db::name('categories')->insertGetId([
                'parent_id' => 0,
                'name' => 'order-admin-show-cat-' . bin2hex(random_bytes(3)),
                'path' => '/',
                'depth' => 1,
                'sort' => 0,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $courseId = (int) Db::name('courses')->insertGetId([
                'department_id' => null,
                'category_id' => $categoryId,
                'title' => '已发布课程详情',
                'cover_url' => null,
                'teacher_name' => '讲师',
                'summary' => null,
                'intro_rich_text' => null,
                'status' => 'published',
                'price_mode' => 'paid',
                'list_price' => 199,
                'sale_price' => 149,
                'sale_start_at' => $now,
                'sale_end_at' => '2026-08-30 10:00:00',
                'created_by_staff_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $orderId = (int) Db::name('orders')->insertGetId([
                'learner_id' => $learnerId,
                'course_id' => $courseId,
                'learner_coupon_id' => null,
                'list_price_snapshot' => 199,
                'sale_price_snapshot' => 149,
                'coupon_discount_snapshot' => 0,
                'paid_amount' => 149,
                'currency' => 'CNY',
                'status' => 'pending',
                'provider' => 'fake',
                'provider_ref' => null,
                'succeeded_at' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $item = (new OrderService(
                new EntitlementService(),
                new FakePaymentAdapter(),
            ))->adminShow($staffId, $orderId, new DataScopeService());

            self::assertSame('pending', $item['status']);
            self::assertSame('已发布课程详情', $item['course_title']);
        } finally {
            Db::rollback();
        }
    }

    private function controller(): OrderController
    {
        return new OrderController(
            new OrderService(new EntitlementService(), new FakePaymentAdapter()),
            new DataScopeService(),
        );
    }
}
