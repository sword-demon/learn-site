<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\ActivationCodeService;
use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class ActivationCodeController
{
    public function __construct(private readonly ActivationCodeService $codes)
    {
    }

    public function redeem(Request $request): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(fn (): array => $this->codes->redeem(
            (int) ($request->account_id ?? 0),
            (string) ($body['code'] ?? ''),
        ));
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('activation_code.redeem.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail(
            match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'CONFLICT' => ApiResponse::CONFLICT,
                default => ApiResponse::VALIDATION_FAILED,
            },
            $exception->getMessage(),
            request()->request_id ?? null,
            $exception->details,
        );
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
