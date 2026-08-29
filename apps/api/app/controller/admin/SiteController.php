<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\SiteService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class SiteController
{
    public function __construct(private readonly SiteService $sites)
    {
    }

    public function show(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->sites->get());
    }

    public function update(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->sites->update(
            (int) ($request->account_id ?? 0),
            self::readJson($request),
        ));
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return ApiResponse::fail(
                match ($exception->apiCode) {
                    'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                    'FORBIDDEN' => ApiResponse::FORBIDDEN,
                    default => ApiResponse::VALIDATION_FAILED,
                },
                $exception->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $exception) {
            Logger::error('site.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
