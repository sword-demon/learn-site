<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\OpsInboxService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class OpsInboxController
{
    public function __construct(private readonly OpsInboxService $opsInbox)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request): array {
            $staffId = $this->staffId($request);
            return $this->opsInbox->list($staffId, $this->permissions($request), [
                'source_type' => $this->optionalEnum($request->get('source_type'), ['course_unpublished', 'map_anomaly', 'question_pending', 'feedback_pending', 'payment_unknown', 'queue_failed', 'long_pending'], 'OPS_SOURCE_INVALID'),
                'state' => $this->enum($request->get('state', 'open'), ['open', 'retrying', 'snoozed', 'resolved', 'assigned'], 'OPS_STATE_INVALID'),
                'age_min_hours' => $this->optionalInt($request->get('age_min_hours'), 0, 720, 'OPS_AGE_INVALID'),
                'sort_by' => $this->enum($request->get('sort_by', 'weight'), ['weight', 'age_seconds'], 'OPS_SORT_INVALID'),
                'sort_dir' => $this->enum($request->get('sort_dir', 'desc'), ['asc', 'desc'], 'OPS_SORT_INVALID'),
                'page' => $this->positiveInt($request->get('page', 1), 'OPS_PAGE_INVALID'),
                'limit' => $this->limit($request->get('limit', 20)),
            ]);
        });
    }

    public function transition(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->opsInbox->transitionState($this->staffId($request), $id, $this->readJson($request)));
    }

    public function retry(Request $request, string $source_key): \support\Response
    {
        return $this->wrap(fn (): array => $this->opsInbox->retry($this->staffId($request), $source_key, $this->permissions($request)));
    }

    public function sweep(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => ['count' => $this->opsInbox->sweep()]);
    }

    private function staffId(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        return $id;
    }

    /** @return list<string> */
    private function permissions(Request $request): array
    {
        $permissions = $request->permissions ?? [];
        return is_array($permissions) ? array_values(array_filter($permissions, 'is_string')) : [];
    }

    /** @param list<string> $allowed */
    private function enum(mixed $value, array $allowed, string $error): string
    {
        $value = (string) $value;
        if (!in_array($value, $allowed, true)) throw new BusinessException('VALIDATION_FAILED', $error);
        return $value;
    }

    /** @param list<string> $allowed */
    private function optionalEnum(mixed $value, array $allowed, string $error): ?string
    {
        if ($value === null || $value === '') return null;
        return $this->enum($value, $allowed, $error);
    }

    private function positiveInt(mixed $value, string $error): int
    {
        if (is_string($value) && !ctype_digit($value)) throw new BusinessException('VALIDATION_FAILED', $error);
        $value = (int) $value;
        if ($value < 1) throw new BusinessException('VALIDATION_FAILED', $error);
        return $value;
    }

    private function optionalInt(mixed $value, int $min, int $max, string $error): ?int
    {
        if ($value === null || $value === '') return null;
        if (is_string($value) && !preg_match('/^\d+$/', $value)) throw new BusinessException('VALIDATION_FAILED', $error);
        $value = (int) $value;
        if ($value < $min || $value > $max) throw new BusinessException('VALIDATION_FAILED', $error);
        return $value;
    }

    private function limit(mixed $value): int
    {
        $limit = $this->positiveInt($value, 'OPS_LIMIT_INVALID');
        if ($limit > 50) throw new BusinessException('VALIDATION_FAILED', 'OPS_LIMIT_TOO_LARGE');
        return $limit;
    }

    /** @return array<string,mixed> */
    private function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        $decoded = $raw === '' ? [] : json_decode($raw, true);
        if (!is_array($decoded)) throw new BusinessException('VALIDATION_FAILED', 'INVALID_JSON');
        return $decoded;
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return ApiResponse::fail($this->mapApiCode($exception->apiCode), $exception->getMessage(), request()->request_id ?? null);
        } catch (\Throwable $exception) {
            Logger::error('ops_inbox.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND' => ApiResponse::NOT_FOUND,
            'FORBIDDEN' => ApiResponse::FORBIDDEN,
            'CONFLICT' => ApiResponse::CONFLICT,
            'INTERNAL' => ApiResponse::INTERNAL,
            default => ApiResponse::VALIDATION_FAILED,
        };
    }
}
