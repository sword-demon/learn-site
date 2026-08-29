<?php

declare(strict_types=1);

namespace App\scheduled;

/**
 * Code-registered scheduled task handler.
 */
interface ScheduledTaskHandler
{
    public function code(): string;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed> context for run log
     */
    public function execute(array $params): array;

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function normalizeParams(array $params): array;
}
