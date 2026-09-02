<?php

declare(strict_types=1);

namespace App\scheduled\handler;

use App\scheduled\ScheduledTaskHandler;
use App\service\OrderService;
use support\Container;

final class ExpiredOrderCancellationHandler implements ScheduledTaskHandler
{
    public function code(): string
    {
        return 'order.cancel_expired';
    }

    public function execute(array $params): array
    {
        /** @var OrderService $orders */
        $orders = Container::get(OrderService::class);
        return ['cancelled' => $orders->cancelExpiredPending(15, (int) $params['batch_size'])];
    }

    public function normalizeParams(array $params): array
    {
        $batchSize = (int) ($params['batch_size'] ?? 200);
        return ['batch_size' => max(1, min(200, $batchSize))];
    }
}
