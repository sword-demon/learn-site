<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\EntitlementService;
use App\service\OrderService;
use App\support\ApiResponse;
use support\Request;

/**
 * OrderController — Phase 6 / US3 endpoints that touch the order book:
 *
 *   - POST /courses/{id}/orders  create a pending order + payment payload
 *   - GET  /orders               list the caller's orders
 *   - GET  /orders/{id}          fetch one order; only the owner
 *
 * Payment is driven by FR-094's FakePaymentAdapter for the MVP. The
 * real WeChat Native integration is deferred past Phase 10 and
 * intentionally left out of this controller.
 *
 *   // ponytail: we never expose a "mark-as-paid" endpoint here.
 *   Only the payment provider's async callback can flip an order to
 *   succeeded. The fake adapter exposes that callback at /internal/...
 *   which is a separate, non-public route registered in route.php.
 */
final class OrderController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly EntitlementService $entitlements,
    ) {}

    /**
     * POST /api/learner/v1/courses/{id}/orders
     *
     * 409 when:
     *   - the course is free (use /start)
     *   - the learner already holds an active entitlement
     *
     * Returns the payment envelope: { order_id, status: 'pending',
     *   payment: { type, code_url, ... } }.
     */
    public function create(Request $request, string $id): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $courseId = (int) $id;
        if ($courseId <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'COURSE_INVALID');
        }
        if ($this->entitlements->viewerAuthorized($courseId, $learnerId)) {
            return ApiResponse::fail(ApiResponse::CONFLICT, 'ALREADY_ENTITLED');
        }
        $body = $this->readJson($request);
        $couponId = $this->parseCouponId($body['learner_coupon_id'] ?? null);
        try {
            $payload = $this->orders->createPending($learnerId, $courseId, $couponId);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
        return ApiResponse::ok($payload);
    }

    private function parseCouponId(mixed $value): ?int
    {
        if ($value === null || $value === '' || $value === false) {
            return null;
        }
        if (is_int($value) && $value > 0) {
            return $value;
        }
        if (is_string($value) && ctype_digit($value) && (int) $value > 0) {
            return (int) $value;
        }
        throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'INVALID_COUPON_ID');
    }

    /** @return array<string,mixed> */
    private function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * GET /api/learner/v1/orders?status=&page=1&limit=20
     */
    public function index(Request $request): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $page = max(1, (int) ($request->get('page') ?? 1));
        $limit = min(50, max(1, (int) ($request->get('limit') ?? 20)));
        $status = $request->get('status');
        $items = $this->orders->listForLearner($learnerId, $status, $page, $limit);
        $total = $this->orders->countForLearner($learnerId, $status);
        return ApiResponse::ok([
            'items' => $items,
            'page'  => $page,
            'limit' => $limit,
            'total' => $total,
        ]);
    }

    /**
     * GET /api/learner/v1/orders/{id}
     *
     * Returns 404 when the order belongs to another learner. We never
     * confirm existence-vs-ownership with separate error codes — that
     * would leak the order id space.
     */
    public function show(Request $request, string $id): \support\Response
    {
        $learnerId = $this->requireLearner($request);
        $orderId = (int) $id;
        if ($orderId <= 0) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ORDER_NOT_FOUND');
        }
        $order = $this->orders->findForLearner($learnerId, $orderId);
        if ($order === null) {
            return ApiResponse::fail(ApiResponse::NOT_FOUND, 'ORDER_NOT_FOUND');
        }
        return ApiResponse::ok($order);
    }

    private function requireLearner(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) {
            throw new BusinessException(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
        }
        return $id;
    }
}
