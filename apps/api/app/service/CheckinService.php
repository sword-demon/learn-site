<?php

declare(strict_types=1);

namespace App\service;

use App\support\ApiResponse;
use App\support\HtmlSanitizer;
use support\think\Db;
use think\db\exception\PDOException as ThinkPdoException;

/**
 * Daily check-in business rules for learner and admin surfaces.
 */
final class CheckinService
{
    private const MAX_PLAN_LENGTH = 10_000;
    private const TIMEZONE = 'Asia/Shanghai';

    /**
     * @return array<string, mixed>
     */
    public function create(int $learnerId, string $planHtml): array
    {
        $this->assertActiveLearner($learnerId);
        $sanitized = $this->sanitizePlan($planHtml);
        $today = $this->todayDate();
        $now = $this->nowDatetime();

        try {
            $id = (int) Db::transaction(function () use ($learnerId, $today, $sanitized, $now): int {
                $id = (int) Db::name('learner_daily_checkins')->insertGetId([
                    'learner_id' => $learnerId,
                    'checkin_date' => $today,
                    'plan_html' => $sanitized,
                    'checked_in_at' => $now,
                    'created_at' => $now,
                ]);
                $this->writeAudit(
                    $learnerId,
                    'checkin.create',
                    $id,
                    ['checkin_date' => $today],
                    $now,
                );
                return $id;
            });
        } catch (\Throwable $exception) {
            if (!$this->isDuplicateKey($exception)) {
                throw $exception;
            }

            $existingId = (int) Db::name('learner_daily_checkins')
                ->where('learner_id', $learnerId)
                ->where('checkin_date', $today)
                ->value('id');
            $this->writeAudit(
                $learnerId,
                'checkin.duplicate_rejected',
                $existingId > 0 ? $existingId : null,
                ['checkin_date' => $today],
                $now,
            );
            throw new BusinessException(ApiResponse::ALREADY_CHECKED_IN, ApiResponse::ALREADY_CHECKED_IN);
        }

        return $this->shapeLearnerRecord([
            'id' => $id,
            'checkin_date' => $today,
            'plan_html' => $sanitized,
            'checked_in_at' => $now,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTodayStatus(int $learnerId): array
    {
        $today = $this->todayDate();
        $row = Db::name('learner_daily_checkins')
            ->where('learner_id', $learnerId)
            ->where('checkin_date', $today)
            ->find();

        return [
            'server_date' => $today,
            'checked_in' => is_array($row),
            'record' => is_array($row) ? $this->shapeLearnerRecord($row) : null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function listForLearner(int $learnerId, array $filters): array
    {
        [$page, $limit] = $this->normalizePagination($filters);
        $query = Db::name('learner_daily_checkins')
            ->where('learner_id', $learnerId)
            ->order('checkin_date', 'desc')
            ->order('id', 'desc');
        $total = (int) (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map(fn (array $row): array => $this->shapeLearnerRecord($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForLearner(int $learnerId, int $id): array
    {
        $row = Db::name('learner_daily_checkins')
            ->where('id', $id)
            ->where('learner_id', $learnerId)
            ->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'CHECKIN_NOT_FOUND');
        }
        return $this->shapeLearnerRecord($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: list<array<string, mixed>>, total: int, page: int, limit: int}
     */
    public function listForAdmin(array $filters): array
    {
        [$page, $limit] = $this->normalizePagination($filters);
        $query = Db::name('learner_daily_checkins')
            ->alias('c')
            ->leftJoin('accounts a', 'a.id = c.learner_id')
            ->leftJoin('learners l', 'l.account_id = c.learner_id')
            ->field('c.*, a.login, l.nickname')
            ->order('c.checked_in_at', 'desc')
            ->order('c.id', 'desc');

        $learnerId = (int) ($filters['learner_id'] ?? 0);
        if ($learnerId > 0) {
            $query->where('c.learner_id', $learnerId);
        }
        $dateFrom = trim((string) ($filters['date_from'] ?? ''));
        if ($dateFrom !== '') {
            $query->where('c.checkin_date', '>=', $dateFrom);
        }
        $dateTo = trim((string) ($filters['date_to'] ?? ''));
        if ($dateTo !== '') {
            $query->where('c.checkin_date', '<=', $dateTo);
        }

        $total = (int) (clone $query)->count();
        $rows = $query->page($page, $limit)->select()->toArray();

        return [
            'items' => array_map(fn (array $row): array => $this->shapeAdminListItem($row), $rows),
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForAdmin(int $id): array
    {
        $row = Db::name('learner_daily_checkins')
            ->alias('c')
            ->leftJoin('accounts a', 'a.id = c.learner_id')
            ->leftJoin('learners l', 'l.account_id = c.learner_id')
            ->field('c.*, a.login, l.nickname')
            ->where('c.id', $id)
            ->find();
        if (!is_array($row)) {
            throw new BusinessException('NOT_FOUND', 'CHECKIN_NOT_FOUND');
        }
        return $this->shapeAdminDetail($row);
    }

    public function deleteForAdmin(int $staffId, int $id): void
    {
        if ($staffId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        Db::transaction(function () use ($staffId, $id): void {
            $row = Db::name('learner_daily_checkins')->where('id', $id)->lock(true)->find();
            if (!is_array($row)) {
                throw new BusinessException('NOT_FOUND', 'CHECKIN_NOT_FOUND');
            }

            Db::name('learner_daily_checkins')->where('id', $id)->delete();
            $this->writeAudit(
                $staffId,
                'checkin.delete',
                $id,
                [
                    'learner_id' => (int) $row['learner_id'],
                    'checkin_date' => (string) $row['checkin_date'],
                ],
                $this->nowDatetime(),
            );
        });
    }

    private function assertActiveLearner(int $learnerId): void
    {
        if ($learnerId <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        $account = Db::name('accounts')
            ->where('id', $learnerId)
            ->where('kind', 'learner')
            ->find();
        if (!is_array($account) || (string) $account['status'] !== 'active') {
            throw new BusinessException(ApiResponse::ACCOUNT_DISABLED, ApiResponse::ACCOUNT_DISABLED);
        }
        $learner = Db::name('learners')->where('account_id', $learnerId)->find();
        if (!is_array($learner)) {
            throw new BusinessException(ApiResponse::ACCOUNT_DISABLED, ApiResponse::ACCOUNT_DISABLED);
        }
    }

    private function sanitizePlan(string $planHtml): string
    {
        $result = HtmlSanitizer::sanitize($planHtml);
        if ($result['truncated']) {
            throw new BusinessException('VALIDATION_FAILED', 'PLAN_HTML_TOO_LARGE');
        }
        $html = $result['html'];
        if (!$this->hasVisibleText($html)) {
            throw new BusinessException('VALIDATION_FAILED', 'PLAN_HTML_REQUIRED');
        }
        if (mb_strlen($this->plainText($html)) > self::MAX_PLAN_LENGTH) {
            throw new BusinessException('VALIDATION_FAILED', 'PLAN_HTML_TOO_LARGE');
        }
        return $html;
    }

    private function hasVisibleText(string $html): bool
    {
        return $this->plainText($html) !== '';
    }

    private function plainText(string $html): string
    {
        $text = preg_replace('/<br\s*\/?>/i', ' ', $html) ?? $html;
        $text = strip_tags($text);
        $text = str_replace("\xc2\xa0", ' ', $text);
        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeLearnerRecord(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'checkin_date' => (string) $row['checkin_date'],
            'plan_html' => (string) $row['plan_html'],
            'checked_in_at' => $this->toIso8601((string) $row['checked_in_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeAdminListItem(array $row): array
    {
        $planHtml = (string) $row['plan_html'];
        return [
            'id' => (int) $row['id'],
            'learner_id' => (int) $row['learner_id'],
            'learner_display_name' => isset($row['nickname']) && $row['nickname'] !== null
                ? (string) $row['nickname']
                : null,
            'learner_phone_masked' => $this->maskPhone((string) ($row['login'] ?? '')),
            'checkin_date' => (string) $row['checkin_date'],
            'plan_summary' => $this->summarizePlan($planHtml),
            'checked_in_at' => $this->toIso8601((string) $row['checked_in_at']),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeAdminDetail(array $row): array
    {
        return [
            ...$this->shapeAdminListItem($row),
            'plan_html' => (string) $row['plan_html'],
        ];
    }

    private function summarizePlan(string $planHtml): string
    {
        $text = $this->plainText($planHtml);
        if (mb_strlen($text) <= 120) {
            return $text;
        }
        return mb_substr($text, 0, 120) . '…';
    }

    private function maskPhone(string $login): string
    {
        if (preg_match('/^\d{11}$/', $login) === 1) {
            return substr($login, 0, 3) . '****' . substr($login, 7);
        }
        if (strlen($login) <= 4) {
            return $login;
        }
        return substr($login, 0, 2) . '****' . substr($login, -2);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: int, 1: int}
     */
    private function normalizePagination(array $filters): array
    {
        $page = max(1, (int) ($filters['page'] ?? 1));
        $limit = max(1, min(100, (int) ($filters['limit'] ?? 20)));
        return [$page, $limit];
    }

    private function todayDate(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d');
    }

    private function nowDatetime(): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('Y-m-d H:i:s');
    }

    private function toIso8601(string $datetime): string
    {
        return (new \DateTimeImmutable($datetime, new \DateTimeZone(self::TIMEZONE)))->format(DATE_ATOM);
    }

    /** @param array<string, int|string> $payload */
    private function writeAudit(
        int $actorId,
        string $action,
        ?int $targetId,
        array $payload,
        string $createdAt,
    ): void {
        Db::name('audit_log')->insert([
            'actor_id' => $actorId,
            'action' => $action,
            'target_type' => 'learner_daily_checkin',
            'target_id' => $targetId,
            'payload_json' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $createdAt,
        ]);
    }

    private function isDuplicateKey(\Throwable $exception): bool
    {
        if ($exception instanceof ThinkPdoException) {
            $info = $exception->getData()['PDO Error Info'] ?? [];
            return ($info['SQLSTATE'] ?? null) === '23000'
                && (int) ($info['Driver Error Code'] ?? 0) === 1062;
        }
        if ($exception instanceof \PDOException) {
            return ($exception->errorInfo[0] ?? null) === '23000'
                && (int) ($exception->errorInfo[1] ?? 0) === 1062;
        }
        return false;
    }
}
