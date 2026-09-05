<?php

declare(strict_types=1);

namespace App\scheduled\handler;

use App\scheduled\ScheduledTaskHandler;
use App\service\LearningReminderService;

final class LearningReminderHandler implements ScheduledTaskHandler
{
    public function __construct(private readonly LearningReminderService $reminders = new LearningReminderService())
    {
    }

    public function code(): string
    {
        return 'learner.reminder.evaluate';
    }

    /** @return array<string, mixed> */
    public function execute(array $params): array
    {
        $lock = 'learner-reminder-evaluate';
        if ((int) \support\think\Db::query("SELECT GET_LOCK('{$lock}', 0) AS acquired")[0]['acquired'] !== 1) {
            return ['processed' => 0, 'sent' => 0, 'failed' => 0, 'batch_size' => 0, 'skipped' => true];
        }
        try {
            return $this->reminders->evaluateBatch((int) ($params['batch_size'] ?? 200));
        } finally {
            \support\think\Db::query("SELECT RELEASE_LOCK('{$lock}')");
        }
    }

    /** @return array<string, mixed> */
    public function normalizeParams(array $params): array
    {
        return ['batch_size' => max(1, min(200, (int) ($params['batch_size'] ?? 200)))];
    }
}
