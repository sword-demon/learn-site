<?php
declare(strict_types=1);

namespace App\queue\redis;

use App\service\OpsInboxService;
use Webman\RedisQueue\Consumer;

final class OpsInboxSweepConsumer implements Consumer
{
    public string $queue = 'ops-inbox-sweep';
    public string $connection = 'default';

    public function consume(mixed $data): void
    {
        (new OpsInboxService())->sweep();
    }
}
