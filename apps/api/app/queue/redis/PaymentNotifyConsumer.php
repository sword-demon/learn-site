<?php

declare(strict_types=1);

namespace App\queue\redis;

use App\service\OrderService;
use Webman\RedisQueue\Consumer;

final class PaymentNotifyConsumer implements Consumer
{
    public string $queue = 'payment.notify';

    public string $connection = 'default';

    public function consume(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }
        $orderId = (int) ($data['order_id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        $providerRef = (string) ($data['provider_ref'] ?? '');
        if ($orderId <= 0 || $status === '') {
            return;
        }

        /** @var OrderService $orders */
        $orders = \support\Container::get(OrderService::class);

        match ($status) {
            'succeeded' => $orders->markSucceeded($orderId, $providerRef),
            'failed', 'cancelled', 'unknown' => $orders->markFailed($orderId, $status, $providerRef !== '' ? $providerRef : null),
            default => null,
        };
    }
}
