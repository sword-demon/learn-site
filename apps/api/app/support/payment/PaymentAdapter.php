<?php
declare(strict_types=1);

namespace App\support\payment;

/**
 * PaymentAdapter — single seam between the order book and the payment
 * provider. Phase 6 ships one implementation (Fake); the future real
 * WeChat Native adapter will land in a Phase beyond MVP and slot in
 * via DI without touching OrderService.
 *
 * Contract:
 *   - createCharge() returns whatever the controller will surface to
 *     the client: a code_url for Native QR, a redirect URL for H5,
 *     etc. Fake returns a stable test-mode string + a synthetic
 *     out_trade_no.
 *   - parseNotify() reads the inbound request body + headers and
 *     normalises into a NotifyResult. The caller (PaymentNotifyController)
 *     decides what to do with it — but Fake returns nothing that
 *     requires signature verification, since there's no signature.
 */
interface PaymentAdapter
{
    /**
     * Build a charge payload for the learner to pay.
     *
     * @return array<string,mixed> keys: type ('wechat_native'|...), plus
     *         provider-specific fields (e.g. code_url).
     */
    public function createCharge(int $orderId, float $amount, string $currency): array;

    /**
     * Parse an inbound provider notification. Implementations should
     * verify the signature when applicable. Returns a NotifyResult or
     * null when the request cannot be parsed / signature is invalid.
     */
    public function parseNotify(\support\Request $request): ?NotifyResult;
}