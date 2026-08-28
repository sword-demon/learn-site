<?php
declare(strict_types=1);

namespace App\support\payment;

use support\Request;
use Workerman\Timer;

/**
 * FakePaymentAdapter — FR-094 test-mode stand-in for the real WeChat
 * Native adapter. Used until Phase 10+ when a real provider is wired
 * in.
 *
 * createCharge() returns a deterministic synthetic QR string for the
 * learner to "scan". The application automatically settles success after
 * the configured delay; tests may use the non-production callback seam for
 * failed|cancelled|unknown states.
 *
 * The provider name surfaced to the order row is 'fake' so the admin
 * audit trail is honest about what happened.
 *
 * parseNotify() reads X-Fake-Payment-Result + a JSON body that names
 * the order id. It does not require a signature — FR-094 explicitly
 * accepts this for the test-mode adapter.
 *
 *   // ponytail: this adapter will be replaced by WechatNativeAdapter
 *   once the merchant onboarding lands. Until then, every order row
 *   gets provider='fake' so the admin UI can flag them as test
 *   transactions if it ever needs to.
 */
final class FakePaymentAdapter implements PaymentAdapter
{
    private const HEADER = 'X-Fake-Payment-Result';
    private const ALLOWED = ['succeeded', 'failed', 'cancelled', 'unknown'];

    private readonly int $delayMs;
    private ?\Closure $successHandler = null;

    public function __construct(?int $delayMs = null)
    {
        $this->delayMs = max(0, $delayMs ?? (int) (getenv('FAKE_PAYMENT_DELAY_MS') ?: 3000));
    }

    public function createCharge(int $orderId, float $amount, string $currency): array
    {
        $codeUrl = sprintf('fake://wechat-native?order_id=%d&amount=%.2f&currency=%s', $orderId, $amount, $currency);
        return [
            'type'         => 'wechat_native',
            'code_url'     => $codeUrl,
            'out_trade_no' => 'fake-' . $orderId,
            'amount'       => $amount,
            'currency'     => $currency,
            'provider'     => 'fake',
        ];
    }

    /**
     * Connect the adapter's automatic success callback to the order service.
     * The adapter never writes orders directly, which keeps the payment port
     * independent from persistence and leaves the test notify seam intact.
     */
    public function setSuccessHandler(callable $handler): void
    {
        $this->successHandler = \Closure::fromCallable($handler);
    }

    /** Schedule the MVP's success-only fake settlement after the configured delay. */
    public function scheduleSuccess(int $orderId): void
    {
        if ($this->successHandler === null) {
            return;
        }

        $settle = function () use ($orderId): void {
            ($this->successHandler)($orderId, 'fake-' . $orderId);
        };
        if ($this->delayMs === 0) {
            $settle();
            return;
        }

        try {
            Timer::add($this->delayMs / 1000, $settle, [], false);
        } catch (\RuntimeException) {
            // Unit tests have no Workerman event loop. The real HTTP worker
            // always has one; without it we fail closed and leave pending.
        }
    }

    public function parseNotify(Request $request): ?NotifyResult
    {
        $result = strtolower((string) ($request->header(self::HEADER) ?? ''));
        if (!in_array($result, self::ALLOWED, true)) {
            return null;
        }
        $raw = (string) $request->rawBody();
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            }
        }
        $orderId = (int) ($body['order_id'] ?? 0);
        if ($orderId <= 0) {
            return null;
        }
        $ref = isset($body['out_trade_no']) ? (string) $body['out_trade_no'] : null;

        return match ($result) {
            'succeeded' => NotifyResult::succeeded($orderId, $ref),
            'failed'    => NotifyResult::failed($orderId, $ref),
            'cancelled' => NotifyResult::cancelled($orderId, $ref),
            default     => NotifyResult::unknown($orderId, $ref),
        };
    }
}
