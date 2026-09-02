<?php

declare(strict_types=1);

namespace App\queue\redis;

use App\service\NotificationFanOutExecutor;
use Webman\RedisQueue\Consumer;

final class NotificationFanOutConsumer implements Consumer
{
    public string $queue = 'notification.fan_out';

    public string $connection = 'default';

    public function consume(mixed $data): void
    {
        (new NotificationFanOutExecutor())->run(is_array($data) ? $data : []);
    }
}
