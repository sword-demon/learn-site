<?php
declare(strict_types=1);

namespace App\controller\internal;

use App\queue\QueueNames;
use App\service\BusinessException;
use App\service\OrderService;
use App\support\ApiResponse;
use App\support\Logger;
use App\support\payment\PaymentAdapter;
use App\support\payment\ZPayPaymentAdapter;
use App\support\queue\JobDispatcher;
use support\Request;
use support\think\Db;

/**
 * PaymentNotifyController — internal webhook endpoints for payment
 * providers. Phase 6 only ships one:
 *
 *   POST /api/internal/v1/payments/fake/notify (testing only)
 *     Header: X-Fake-Payment-Result: succeeded|failed|cancelled|unknown
 *     Body:   { "order_id": <int>, "out_trade_no"?: <string> }
 *
 * The real WeChat Native callback route and APIv3 signature verification are
 * reserved for a phase beyond MVP and are not registered here.
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
    private readonly JobDispatcher $jobs;

    public function __construct(
        private readonly OrderService $orders,
        private readonly PaymentAdapter $payment,
        ?JobDispatcher $jobs = null,
    ) {
        $this->jobs = $jobs ?? new JobDispatcher($orders);
    }

    public function fake(Request $request): \support\Response
    {
        if (getenv('APP_ENV') !== 'testing') {
            return ApiResponse::fail(ApiResponse::FORBIDDEN, 'PAYMENT_NOTIFY_NOT_AVAILABLE');
        }
        return $this->dispatch($request);
    }

    public function zpayNotify(Request $request): \support\Response
    {
        return $this->dispatch($request, true);
    }

    public function zpayReturn(Request $request): \support\Response
    {
        $base = rtrim((string) (getenv('APP_BASE_URL') ?: 'http://localhost:8080'), '/');
        if (!$this->payment instanceof ZPayPaymentAdapter) {
            return $this->redirect($base . '/orders?status=invalid');
        }

        $payload = $this->payment->verifySignature($request);
        $this->logZpayCallback($request, $payload !== null, $payload === null ? 'invalid_signature' : null);
        if ($payload === null || !ctype_digit((string) $payload['out_trade_no'])) {
            $this->audit('zpay.return.invalid_signature', null, []);
            return $this->redirect($base . '/orders?status=invalid');
        }

        $orderId = (int) $payload['out_trade_no'];
        $tradeNo = rawurlencode((string) $payload['trade_no']);
        $this->audit('zpay.return.valid', $orderId, []);
        return $this->redirect($base . '/orders/' . $orderId . '?status=pending&trade_no=' . $tradeNo);
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

    private function dispatch(Request $request, bool $auditZpayIssues = false): \support\Response
    {
        try {
            $result = $this->payment->parseNotify($request);
        } catch (\Throwable $e) {
            if ($auditZpayIssues) {
                $this->logZpayCallback($request, false, 'parse_error');
            }
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_PARSE');
        }
        if ($result === null) {
            if ($auditZpayIssues && $this->payment instanceof ZPayPaymentAdapter) {
                $issue = $this->payment->notifyIssue() ?? 'invalid_signature';
                $this->logZpayCallback($request, $this->payment->notifySignatureValid(), $issue);
                if ($issue === 'unknown_order' || $issue === 'amount_mismatch') {
                    $this->audit('zpay.notify.' . $issue, $this->payment->notifyIssueOrderId(), []);
                    return ApiResponse::ok(['received' => true, 'status' => 'ignored']);
                }
                $this->audit('zpay.notify.' . $issue, $this->payment->notifyIssueOrderId(), []);
            }
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_INVALID');
        }
        if ($auditZpayIssues) {
            $this->logZpayCallback($request, true, null);
        }

        if (!in_array($result->status, ['succeeded', 'failed', 'cancelled', 'unknown'], true)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'PAYMENT_NOTIFY_STATUS');
        }

        if ($this->shouldDispatchAsync()) {
            try {
                $this->jobs->dispatch(QueueNames::PAYMENT_NOTIFY, [
                    'order_id' => $result->orderId,
                    'status' => $result->status,
                    'provider_ref' => (string) ($result->providerRef ?? ''),
                    'audit_zpay' => $auditZpayIssues,
                ]);
            } catch (\Throwable $e) {
                Logger::error('payment.notify.dispatch_failed', ['err' => $e->getMessage()]);
                return ApiResponse::fail(ApiResponse::INTERNAL, 'PAYMENT_NOTIFY_DISPATCH');
            }
            return ApiResponse::ok(['received' => true, 'status' => $result->status]);
        }

        try {
            $changed = false;
            switch ($result->status) {
                case 'succeeded':
                    $changed = $this->orders->markSucceeded($result->orderId, (string) ($result->providerRef ?? ''));
                    break;
                case 'failed':
                case 'cancelled':
                case 'unknown':
                    $changed = $this->orders->markFailed($result->orderId, $result->status, $result->providerRef);
                    break;
            }
            if ($auditZpayIssues && $changed) {
                $this->audit('zpay.notify.' . $result->status, $result->orderId, [
                    'provider_ref' => $result->providerRef,
                ]);
            }
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        } catch (\Throwable $e) {
            return ApiResponse::fail(ApiResponse::INTERNAL, 'PAYMENT_NOTIFY_DISPATCH');
        }

        return ApiResponse::ok(['received' => true, 'status' => $result->status]);
    }

    private function redirect(string $location): \support\Response
    {
        return response('', 302)->withHeader('Location', $location);
    }

    /** @param array<string, mixed> $payload */
    private function audit(string $action, ?int $targetId, array $payload): void
    {
        Db::name('audit_log')->insert([
            'actor_id' => 0,
            'action' => $action,
            'target_type' => 'order',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    private function logZpayCallback(Request $request, bool $signatureValid, ?string $issue): void
    {
        $payload = array_merge($request->get(), $request->post());
        $context = ['signature_valid' => $signatureValid];
        foreach (['trade_no', 'out_trade_no', 'trade_status', 'money', 'type'] as $field) {
            if (isset($payload[$field]) && is_scalar($payload[$field])) {
                $context[$field] = (string) $payload[$field];
            }
        }
        if ($issue !== null) {
            $context['issue'] = $issue;
        }
        Logger::info('zpay.notify.received', $context);
    }

    private function shouldDispatchAsync(): bool
    {
        $flag = getenv('PAYMENT_NOTIFY_ASYNC');
        if ($flag === false || $flag === '') {
            return true;
        }
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }
}
