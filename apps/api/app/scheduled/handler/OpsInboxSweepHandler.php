<?php
declare(strict_types=1);

namespace App\scheduled\handler;

use App\scheduled\ScheduledTaskHandler;
use App\service\OpsInboxService;

final class OpsInboxSweepHandler implements ScheduledTaskHandler
{
    public function __construct(private readonly OpsInboxService $opsInbox = new OpsInboxService())
    {
    }

    public function code(): string
    {
        return 'ops_inbox.sweep';
    }

    public function execute(array $params): array
    {
        return ['count' => $this->opsInbox->sweep()];
    }

    public function normalizeParams(array $params): array
    {
        return [];
    }
}
