<?php

declare(strict_types=1);

namespace Tests;

use App\controller\learner\OrderController;
use App\service\EntitlementService;
use App\service\OrderService;
use App\support\payment\FakePaymentAdapter;
use PHPUnit\Framework\TestCase;
use support\App;
use support\Request;
use support\think\Db;
use Webman\ThinkOrm\ThinkOrm;

final class LearnerOrderDetailTest extends TestCase
{
    private int $learnerId;
    private int $otherLearnerId;
    private int $courseId;
    private int $orderId;

    public static function setUpBeforeClass(): void
    {
        App::loadAllConfig(['route', 'container']);
        ThinkOrm::start(null);
    }

    protected function setUp(): void
    {
        Db::startTrans();
        $now = '2026-08-20 10:00:00';
        $this->learnerId = $this->createAccount();
        $this->otherLearnerId = $this->createAccount();
        $categoryId = (int) Db::name('categories')->insertGetId([
            'parent_id' => 0,
            'name' => 'order-detail-category-' . bin2hex(random_bytes(3)),
            'path' => '/',
            'depth' => 1,
            'sort' => 0,
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $this->courseId = (int) Db::name('courses')->insertGetId([
            'department_id' => null,
            'category_id' => $categoryId,
            'title' => '订单详情课程',
            'cover_url' => null,
            'teacher_name' => '测试讲师',
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
        $this->orderId = (int) Db::name('orders')->insertGetId([
            'learner_id' => $this->learnerId,
            'course_id' => $this->courseId,
            'learner_coupon_id' => null,
            'list_price_snapshot' => 199,
            'sale_price_snapshot' => 149,
            'coupon_discount_snapshot' => 20,
            'paid_amount' => 129,
            'currency' => 'CNY',
            'status' => 'succeeded',
            'provider' => 'fake',
            'provider_ref' => 'ref-100',
            'succeeded_at' => '2026-08-20 10:01:00',
            'created_at' => $now,
            'updated_at' => '2026-08-20 10:01:00',
        ]);
    }

    protected function tearDown(): void
    {
        Db::rollback();
    }

    public function testShowIncludesCourseTitleForTheOwningLearner(): void
    {
        $request = new Request("GET /api/learner/v1/orders/{$this->orderId} HTTP/1.1\r\nHost: test\r\n\r\n");
        $request->account_id = $this->learnerId;
        $payload = json_decode((string) $this->controller()->show($request, (string) $this->orderId)->rawBody(), true);

        self::assertTrue($payload['ok'] ?? false);
        self::assertSame('订单详情课程', $payload['data']['course_title'] ?? null);
        self::assertSame(20.0, (float) ($payload['data']['coupon_discount_snapshot'] ?? -1));
    }

    public function testShowHidesAnotherLearnersOrder(): void
    {
        $request = new Request("GET /api/learner/v1/orders/{$this->orderId} HTTP/1.1\r\nHost: test\r\n\r\n");
        $request->account_id = $this->otherLearnerId;
        $response = $this->controller()->show($request, (string) $this->orderId);
        $payload = json_decode((string) $response->rawBody(), true);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('ORDER_NOT_FOUND', $payload['error']['message'] ?? null);
    }

    private function controller(): OrderController
    {
        return new OrderController(
            new OrderService(new EntitlementService(), new FakePaymentAdapter()),
            new EntitlementService(),
        );
    }

    private function createAccount(): int
    {
        return (int) Db::name('accounts')->insertGetId([
            'kind' => 'learner',
            'login' => '139' . random_int(10000000, 99999999),
            'password_hash' => 'x',
            'must_change_password' => 0,
            'status' => 'active',
            'last_login_at' => null,
            'created_at' => '2026-08-20 10:00:00',
            'updated_at' => '2026-08-20 10:00:00',
        ]);
    }
}
