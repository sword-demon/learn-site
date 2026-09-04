<?php

declare(strict_types=1);

namespace App\queue\redis;

use App\service\OrderService;
use App\support\Logger;
use support\think\Db;
use Webman\RedisQueue\Consumer;

final class PaymentNotifyConsumer implements Consumer
{
    public string $queue = 'payment.notify';

    public string $connection = 'default';

    public function __construct(private readonly ?OrderService $orders = null)
    {
    }

    public function consume(mixed $data): void
    {
        if (!is_array($data)) {
            return;
        }
        $orderId = (int) ($data['order_id'] ?? 0);
        $status = (string) ($data['status'] ?? '');
        $providerRef = (string) ($data['provider_ref'] ?? '');
        $auditZpay = ($data['audit_zpay'] ?? false) === true;
        if ($orderId <= 0 || $status === '') {
            return;
        }

        /** @var OrderService $orders */
        $orders = $this->orders ?? \support\Container::get(OrderService::class);

        $changed = match ($status) {
            'succeeded' => $orders->markSucceeded($orderId, $providerRef),
            'failed', 'cancelled', 'unknown' => $orders->markFailed($orderId, $status, $providerRef !== '' ? $providerRef : null),
            default => false,
        };
        if ($auditZpay && $changed) {
            Db::name('audit_log')->insert([
                'actor_id' => 0,
                'action' => 'zpay.notify.' . $status,
                'target_type' => 'order',
                'target_id' => $orderId,
                'payload_json' => json_encode(
                    ['provider_ref' => $providerRef !== '' ? $providerRef : null],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
                ),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            Logger::info('zpay.notify.settled', [
                'order_id' => $orderId,
                'status' => $status,
            ]);
        }
    }
}
