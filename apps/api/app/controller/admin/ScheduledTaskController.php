<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\ScheduledTaskRunService;
use App\service\ScheduledTaskService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class ScheduledTaskController
{
    public function __construct(
        private readonly ScheduledTaskService $tasks,
        private readonly ScheduledTaskRunService $runs,
    ) {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->tasks->list());
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->tasks->show($this->parseId($id)));
    }

    public function validateExpression(Request $request): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(fn (): array => $this->tasks->validateExpression(
            (string) ($body['schedule_expression'] ?? ''),
        ));
    }

    public function update(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->tasks->update(
            $this->parseId($id),
            (int) ($request->account_id ?? 0),
            self::readJson($request),
        ));
    }

    public function run(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->tasks->runNow(
            $this->parseId($id),
            (int) ($request->account_id ?? 0),
        ));
    }

    public function runsIndex(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->runs->list([
            'task_id' => $request->get('task_id'),
            'status' => $request->get('status'),
            'trigger_type' => $request->get('trigger_type'),
            'started_from' => $request->get('started_from'),
            'started_to' => $request->get('started_to'),
            'page' => $request->get('page', 1),
            'per_page' => $request->get('per_page', 20),
        ]));
    }

    public function runsShow(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->runs->show($this->parseId($id)));
    }

    private function parseId(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('scheduled_task.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail($exception->apiCode, $exception->getMessage(), request()->request_id ?? null);
    }

    /**
     * @return array<string, mixed>
     */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);
        return is_array($decoded) ? $decoded : [];
    }
}
