<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\NotificationDispatchService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class NotificationController
{
    public function __construct(private readonly NotificationDispatchService $dispatches)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->dispatches->list([
            'type' => (string) $request->get('type', ''),
            'from' => (string) $request->get('from', ''),
            'to' => (string) $request->get('to', ''),
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
        ]));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->dispatches->show($this->parseId($id)));
    }

    public function retry(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->dispatches->retryFanOut($this->parseId($id)));
    }

    public function storeAnnouncement(Request $request): \support\Response
    {
        return $this->wrapCreated(fn (): array => $this->dispatches->sendAnnouncement(
            (int) ($request->account_id ?? 0),
            (string) (self::readJson($request)['title'] ?? ''),
            (string) (self::readJson($request)['body'] ?? ''),
        ));
    }

    public function storeInternalMessage(Request $request): \support\Response
    {
        $body = self::readJson($request);
        $learnerIds = $body['learner_ids'] ?? [];
        if (!is_array($learnerIds)) {
            $learnerIds = [];
        }
        return $this->wrapCreated(fn (): array => $this->dispatches->sendInternalMessage(
            (int) ($request->account_id ?? 0),
            (string) ($body['title'] ?? ''),
            (string) ($body['body'] ?? ''),
            array_map('intval', $learnerIds),
        ));
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
            Logger::error('notification.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function wrapCreated(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('notification.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail(
            match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                'CONFLICT' => ApiResponse::CONFLICT,
                default => ApiResponse::VALIDATION_FAILED,
            },
            $exception->getMessage(),
            request()->request_id ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
