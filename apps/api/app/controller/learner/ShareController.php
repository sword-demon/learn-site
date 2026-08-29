<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\SharePosterService;
use App\support\ApiResponse;
use App\support\Logger;

final class ShareController
{
    public function __construct(private readonly SharePosterService $shares)
    {
    }

    public function link(string $id): \support\Response
    {
        return $this->wrap(fn(): array => $this->shares->createShareLink($this->id($id)));
    }

    public function poster(string $id): \support\Response
    {
        return $this->wrap(fn(): array => $this->shares->createPoster($this->id($id)));
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw) || (int) $raw <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $raw;
    }

    /** @param callable():array<string,mixed> $operation */
    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            $code = match ($exception->apiCode) {
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
                default => ApiResponse::VALIDATION_FAILED,
            };
            return ApiResponse::fail($code, $exception->getMessage(), request()->request_id ?? null);
        } catch (\Throwable $exception) {
            Logger::error('share.learner.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }
}
