<?php

declare(strict_types=1);

namespace App\scheduled\handler;

use App\scheduled\ScheduledTaskHandler;
use App\service\NotificationCleanupService;

final class NotificationCleanupHandler implements ScheduledTaskHandler
{
    public function __construct(private readonly NotificationCleanupService $cleanup = new NotificationCleanupService())
    {
    }

    public function code(): string
    {
        return 'notification.cleanup';
    }

    public function execute(array $params): array
    {
        $batchSize = (int) ($params['batch_size'] ?? 500);
        $deleted = $this->cleanup->purgeExpired($batchSize);
        return ['deleted' => $deleted];
    }

    public function normalizeParams(array $params): array
    {
        $batchSize = (int) ($params['batch_size'] ?? 500);
        $batchSize = max(1, min(2000, $batchSize));
        return ['batch_size' => $batchSize];
    }
}
