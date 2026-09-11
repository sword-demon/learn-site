<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\LearningFactFunnelService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class LearningFactFunnelController
{
    public function __construct(private readonly LearningFactFunnelService $service)
    {
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id): array {
            if (!ctype_digit($id)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            $window = (int) $request->get('window_days', 30);
            $source = (string) $request->get('source', 'all');
            if ($source === '') {
                $source = 'all';
            }
            return $this->service->show($this->staffId($request), (int) $id, $window, $source);
        });
    }

    private function staffId(Request $request): int
    {
        $id = (int) ($request->account_id ?? 0);
        if ($id <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $id;
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            $code = match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
                default => ApiResponse::VALIDATION_FAILED,
            };
            return ApiResponse::fail($code, $exception->getMessage(), request()->request_id ?? null);
        } catch (\Throwable $exception) {
            Logger::error('learning_fact_funnel.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }
}
