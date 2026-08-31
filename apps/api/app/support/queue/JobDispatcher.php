<?php

declare(strict_types=1);

namespace App\support\queue;

use App\queue\QueueNames;
use App\queue\redis\NotificationFanOutConsumer;
use App\queue\redis\PaymentNotifyConsumer;
use App\queue\redis\PushNotificationConsumer;
use App\queue\redis\ScheduledTaskConsumer;
use App\support\Logger;

/**
 * Queue dispatch with sync fallback for tests (QUEUE_SYNC=1 or APP_ENV=testing).
 */
final class JobDispatcher
{
    public function dispatch(string $queue, array $data): void
    {
        if ($this->shouldRunSync()) {
            $this->consumeSync($queue, $data);
            return;
        }

        if (!class_exists(\Webman\RedisQueue\Client::class)) {
            Logger::error('queue.client_missing', ['queue' => $queue]);
            throw new \RuntimeException('queue_unavailable');
        }

        \Webman\RedisQueue\Client::send($queue, $data);
    }

    private function shouldRunSync(): bool
    {
        $sync = getenv('QUEUE_SYNC');
        if ($sync !== false && $sync !== '' && filter_var($sync, FILTER_VALIDATE_BOOLEAN)) {
            return true;
        }
        return getenv('APP_ENV') === 'testing';
    }

    private function consumeSync(string $queue, array $data): void
    {
        match ($queue) {
            QueueNames::NOTIFICATION_FAN_OUT => (new NotificationFanOutConsumer())->consume($data),
            QueueNames::NOTIFICATION_PUSH => (new PushNotificationConsumer())->consume($data),
            QueueNames::PAYMENT_NOTIFY => (new PaymentNotifyConsumer())->consume($data),
            QueueNames::SCHEDULED_TASK => (new ScheduledTaskConsumer())->consume($data),
            default => throw new \InvalidArgumentException('Unknown queue: ' . $queue),
        };
    }
}
