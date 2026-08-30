<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CheckinService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class CheckinController
{
    public function __construct(private readonly CheckinService $checkins)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->checkins->listForAdmin([
            'learner_id' => (int) $request->get('learner_id', 0),
            'date_from' => (string) $request->get('date_from', ''),
            'date_to' => (string) $request->get('date_to', ''),
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
        ]));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->checkins->getForAdmin($this->parseId($id)));
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        try {
            $this->checkins->deleteForAdmin((int) ($request->account_id ?? 0), $this->parseId($id));
            return response('', 204);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('checkin.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
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
            Logger::error('checkin.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail(
            match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                default => ApiResponse::VALIDATION_FAILED,
            },
            $exception->getMessage(),
            request()->request_id ?? null,
        );
    }
}
