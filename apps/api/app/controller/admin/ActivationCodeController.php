<?php

declare(strict_types=1);

namespace App\controller\admin;

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

    public function createBatch(Request $request, string $courseId): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrapCreated(fn (): array => $this->codes->generateBatch(
            $this->courseId($courseId),
            (int) ($body['quantity'] ?? 0),
            $body['expires_at'] ?? null,
            (int) ($request->account_id ?? 0),
        ));
    }

    public function index(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(fn (): array => $this->codes->listCodes(
            (int) ($request->account_id ?? 0),
            $this->courseId($courseId),
            [
                'status' => (string) ($request->get('status', '') ?? ''),
                'page' => (int) $request->get('page', 1),
                'limit' => (int) $request->get('limit', 20),
            ],
        ));
    }

    public function void(Request $request, string $courseId, string $codeId): \support\Response
    {
        return $this->wrap(fn (): array => $this->codes->voidCode(
            (int) ($request->account_id ?? 0),
            $this->courseId($courseId),
            $this->id($codeId),
        ));
    }

    private function courseId(string $id): int
    {
        return $this->id($id);
    }

    private function id(string $id): int
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
            Logger::error('activation_code.admin.failed', ['err' => $exception->getMessage()]);
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
            Logger::error('activation_code.admin.failed', ['err' => $exception->getMessage()]);
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
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
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
