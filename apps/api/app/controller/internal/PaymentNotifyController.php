<?php
declare(strict_types=1);

namespace App\controller\internal;

use App\service\BusinessException;
use App\service\OrderService;
use App\support\ApiResponse;
use App\support\payment\PaymentAdapter;
use support\Request;

/**
 * PaymentNotifyController — internal webhook endpoints for payment
 * providers. Phase 6 only ships one:
 *
 *   POST /api/internal/v1/payments/fake/notify (testing only)
 *     Header: X-Fake-Payment-Result: succeeded|failed|cancelled|unknown
 *     Body:   { "order_id": <int>, "out_trade_no"?: <string> }
 *
 * The real WeChat Native callback lives at
 *   POST /api/internal/v1/payments/wechat/notify
 * and is reserved for a Phase beyond MVP. It returns an HTTP 200 with
 * a WeChat-shaped acknowledgement so the provider doesn't retry.
 *
 * Notes:
 *   - This endpoint is intentionally NOT authenticated with a learner
 *     token. Providers don't have learner credentials; they sign their
 *     requests. The fake adapter skips signature verification per
 *     FR-094. The real adapter will require a verified signature.
 *   - We always reply 200 OK once the message has been parsed and
 *     dispatched. Idempotency is the caller's responsibility
 *     (markSucceeded / markFailed are idempotent).
 *   - Provider responses that fail to parse return 400 — the caller
 *     (the provider's retry loop) gets a clear signal.
 */
final class PaymentNotifyController
{
    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentAdapter $payment,
    ) {}

    public function fake(Request $request): \support\Response
    {
        return $this->dispatch($request);
    }

    /**
     * Reserved for the real WeChat Native adapter. Not wired to a route
     * in Phase 6 — present so future phases have an obvious place to
     * add it without renaming.
     */
    public function wechat(Request $request): \support\Response
    {
        // ponytail: real adapter lands after MVP. Reject so a future
        // accidental route binding surfaces as a clean 503.
        return ApiResponse::fail(ApiResponse::INTERNAL, 'WECHAT_NOTIFY_NOT_IMPLEMENTED');
    }

    private function dispatch(Request $request): \support\Response
    {
        try {
            $result = $this->payment->parseNotify($request);
        } catch (\Throwable $e) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_PARSE');
        }
        if ($result === null) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_INVALID');
        }

        try {
            switch ($result->status) {
                case 'succeeded':
                    $this->orders->markSucceeded($result->orderId, (string) ($result->providerRef ?? ''));
                    break;
                case 'failed':
                case 'cancelled':
                case 'unknown':
                    $this->orders->markFailed($result->orderId, $result->status, $result->providerRef);
                    break;
                default:
                    return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_STATUS');
            }
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'PAYMENT_NOTIFY_DISPATCH');
        }

        return ApiResponse::ok(['received' => true, 'status' => $result->status]);
    }
}
