<?php

declare(strict_types=1);

namespace app\process;

use App\service\NotificationCleanupService;
use Workerman\Crontab\Crontab;

/**
 * Daily purge of learner inbox rows older than two months.
 */
final class NotificationCleanup
{
    public function onWorkerStart(): void
    {
        new Crontab('0 30 3 * * *', function (): void {
            (new NotificationCleanupService())->purgeExpired();
        });
    }
}
