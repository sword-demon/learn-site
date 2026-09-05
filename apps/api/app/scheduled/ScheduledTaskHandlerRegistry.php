<?php

declare(strict_types=1);

namespace App\scheduled;

use App\scheduled\handler\ExpiredOrderCancellationHandler;
use App\scheduled\handler\NotificationCleanupHandler;
use App\scheduled\handler\LearningReminderHandler;

/**
 * Registry of code-registered scheduled task handlers.
 */
final class ScheduledTaskHandlerRegistry
{
    /** @var array<string, ScheduledTaskHandler> */
    private array $handlers = [];

    public function __construct()
    {
        $this->register(new NotificationCleanupHandler());
        $this->register(new ExpiredOrderCancellationHandler());
        $this->register(new LearningReminderHandler());
    }

    public function register(ScheduledTaskHandler $handler): void
    {
        $this->handlers[$handler->code()] = $handler;
    }

    public function has(string $code): bool
    {
        return isset($this->handlers[$code]);
    }

    public function get(string $code): ?ScheduledTaskHandler
    {
        return $this->handlers[$code] ?? null;
    }

    /** @return list<string> */
    public function codes(): array
    {
        return array_keys($this->handlers);
    }
}
