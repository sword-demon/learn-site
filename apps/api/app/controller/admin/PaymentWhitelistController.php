<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\PaymentWhitelistService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class PaymentWhitelistController
{
    public function __construct(private readonly PaymentWhitelistService $whitelist)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn (): array => $this->whitelist->list(
            (int) $request->get('page', 1),
            (int) $request->get('limit', 20),
        ), $request);
    }

    public function create(Request $request): \support\Response
    {
        $body = self::readJson($request);
        if (isset($body['phone']) && !is_string($body['phone'])) {
            return $this->fail(new BusinessException('VALIDATION_FAILED', 'INVALID_PHONE'), $request);
        }
        if (array_key_exists('enabled', $body) && !is_bool($body['enabled'])) {
            return $this->fail(new BusinessException('VALIDATION_FAILED', 'INVALID_ENABLED'), $request);
        }
        if (array_key_exists('note', $body) && $body['note'] !== null && !is_string($body['note'])) {
            return $this->fail(new BusinessException('VALIDATION_FAILED', 'INVALID_NOTE'), $request);
        }
        return $this->wrap(
            fn (): array => $this->whitelist->get($this->whitelist->add(
                (int) ($request->account_id ?? 0),
                $body['phone'] ?? '',
                $body['enabled'] ?? true,
                $body['note'] ?? null,
            )),
            $request,
            201,
        );
    }

    public function update(Request $request, string $id): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(function () use ($request, $id, $body): array {
            $this->whitelist->update((int) ($request->account_id ?? 0), $this->parseId($id), $body);
            return $this->whitelist->get($this->parseId($id));
        }, $request);
    }

    public function delete(Request $request, string $id): \support\Response
    {
        try {
            $this->whitelist->softDelete((int) ($request->account_id ?? 0), $this->parseId($id));
            return response('', 204);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('payment_whitelist.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', $request->request_id ?? null);
        }
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrap(callable $operation, Request $request, int $status = 200): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), $request->request_id ?? null)->withStatus($status);
        } catch (BusinessException $exception) {
            return $this->fail($exception, $request);
        } catch (\Throwable $exception) {
            Logger::error('payment_whitelist.admin.failed', ['err' => $exception->getMessage()]);
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

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
