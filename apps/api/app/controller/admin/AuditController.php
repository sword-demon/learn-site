<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\ModerationLogService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class AuditController
{
    public function __construct(private readonly ModerationLogService $logs)
    {
    }

    public function index(Request $request): \support\Response
    {
        try {
            $objectType = trim((string) $request->get('object_type', ''));
            $action = trim((string) $request->get('action', ''));
            $actor = (int) $request->get('staff_id', 0);
            return ApiResponse::ok($this->logs->list(
                (int) ($request->account_id ?? 0),
                $objectType !== '' ? $objectType : null,
                $action !== '' ? $action : null,
                $actor > 0 ? $actor : null,
                (int) $request->get('page', 1),
                (int) $request->get('limit', 20),
            ), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return ApiResponse::fail(
                $exception->apiCode === 'UNAUTHENTICATED'
                    ? ApiResponse::UNAUTHENTICATED
                    : ApiResponse::VALIDATION_FAILED,
                $exception->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $exception) {
            Logger::error('moderation_log.list.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }
}
