<?php

declare(strict_types=1);

namespace App\service;

use Workerman\Crontab\Parser;

/**
 * Cron expression validation and next-run prediction (six-field workerman syntax).
 */
final class ScheduleExpressionService
{
    private const MIN_INTERVAL_SECONDS = 60;

    public function isValid(string $expression): bool
    {
        $expression = trim($expression);
        if (!$this->isSixField($expression)) {
            return false;
        }
        return (new Parser())->isValid($expression);
    }

    public function validateForSave(string $expression): void
    {
        if (!$this->isSixField($expression)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_SCHEDULE_EXPRESSION');
        }
        if (!$this->isValid($expression)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_SCHEDULE_EXPRESSION');
        }
        if (!$this->satisfiesMinInterval($expression)) {
            throw new BusinessException('VALIDATION_FAILED', 'MIN_INTERVAL_VIOLATION');
        }
    }

    /** @return array{valid:bool,next_run_at:?string,error:?string} */
    public function preview(string $expression): array
    {
        if (!$this->isSixField($expression) || !$this->isValid($expression)) {
            return [
                'valid' => false,
                'next_run_at' => null,
                'error' => '表达式不符合六段式 cron 语法',
            ];
        }
        if (!$this->satisfiesMinInterval($expression)) {
            return [
                'valid' => false,
                'next_run_at' => null,
                'error' => '调度间隔不得小于 60 秒',
            ];
        }
        $next = $this->nextRunAt($expression);
        return [
            'valid' => true,
            'next_run_at' => $next !== null ? date('Y-m-d H:i:s', $next) : null,
            'error' => null,
        ];
    }

    public function nextRunAt(string $expression, ?int $from = null): ?int
    {
        $expression = trim($expression);
        if (!$this->isValid($expression)) {
            return null;
        }
        $parser = new Parser();
        $from = $from ?? time();
        $deadline = $from + 86400 * 366;
        for ($cursor = $from + 1; $cursor <= $deadline; $cursor++) {
            try {
                $hits = $parser->parse($expression, $cursor);
            } catch (\Throwable) {
                return null;
            }
            if ($hits !== []) {
                return min($hits);
            }
        }
        return null;
    }

    public function satisfiesMinInterval(string $expression, int $minSeconds = self::MIN_INTERVAL_SECONDS): bool
    {
        $first = $this->nextRunAt($expression, time());
        if ($first === null) {
            return false;
        }
        $second = $this->nextRunAt($expression, $first);
        if ($second === null) {
            return true;
        }
        return ($second - $first) >= $minSeconds;
    }

    private function isSixField(string $expression): bool
    {
        $parts = preg_split('/\s+/', trim($expression));
        return is_array($parts) && count($parts) === 6;
    }
}
