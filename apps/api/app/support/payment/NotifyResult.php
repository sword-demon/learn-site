<?php
declare(strict_types=1);

namespace App\support\payment;

/**
 * NotifyResult — normalised view of a payment-provider callback.
 * Status is one of: succeeded|failed|cancelled|unknown. OrderId is
 * the application's order id (NOT the provider's out_trade_no) —
 * adapters are responsible for the translation.
 */
final class NotifyResult
{
    public function __construct(
        public readonly string $status,
        public readonly int $orderId,
        public readonly ?string $providerRef,
    ) {}

    public static function succeeded(int $orderId, ?string $providerRef): self
    {
        return new self('succeeded', $orderId, $providerRef);
    }

    public static function failed(int $orderId, ?string $providerRef = null): self
    {
        return new self('failed', $orderId, $providerRef);
    }

    public static function cancelled(int $orderId, ?string $providerRef = null): self
    {
        return new self('cancelled', $orderId, $providerRef);
    }

    public static function unknown(int $orderId, ?string $providerRef = null): self
    {
        return new self('unknown', $orderId, $providerRef);
    }
}