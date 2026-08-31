<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BannerService;
use App\service\BusinessException;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class BannerController
{
    public function __construct(private readonly BannerService $banners)
    {
    }

    public function index(Request $request): \support\Response
    {
        $isEnabled = $request->get('is_enabled');
        return $this->wrap(fn (): array => $this->banners->listForAdmin([
            'page' => (int) $request->get('page', 1),
            'limit' => (int) $request->get('limit', 20),
            'is_enabled' => $isEnabled,
        ]), $request);
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn (): array => $this->banners->getForAdmin($this->parseId($id)), $request);
    }

    public function store(Request $request): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrapCreated(
            fn (): array => $this->banners->create($body, (int) ($request->account_id ?? 0)),
            $request,
        );
    }

    public function patch(Request $request, string $id): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(
            fn (): array => $this->banners->update(
                $this->parseId($id),
                $body,
                (int) ($request->account_id ?? 0),
            ),
            $request,
        );
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        try {
            $this->banners->softDelete($this->parseId($id), (int) ($request->account_id ?? 0));
            return response('', 204);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('banner.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    private function parseId(string $id): int
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrap(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('banner.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrapCreated(callable $operation, Request $request): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('banner.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception, Request $request): \support\Response
    {
        $code = match ($exception->apiCode) {
            ApiResponse::UNAUTHENTICATED => ApiResponse::UNAUTHENTICATED,
            ApiResponse::NOT_FOUND => ApiResponse::NOT_FOUND,
            ApiResponse::CONFLICT => ApiResponse::CONFLICT,
            default => ApiResponse::VALIDATION_FAILED,
        };
        return ApiResponse::fail($code, $exception->getMessage(), $request->request_id ?? null);
    }

    /** @return array<string,mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
