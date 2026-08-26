<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\LearningMapService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner-facing learning-map surface (Phase 13 / US6).
 *
 *   GET  /api/learner/v1/learning-maps
 *   GET  /api/learner/v1/learning-maps/{id}
 *   POST /api/learner/v1/learning-maps/{id}/start
 *
 * Adding the learner to a map never grants entitlement to its courses
 * (data-model §学习地图: 收费课不因加入地图授权).
 */
final class LearningMapController
{
    public function __construct(private readonly LearningMapService $maps)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn () => $this->maps->learnerListMaps(
            $this->optionalLearnerId($request),
            (int) ($request->get('page') ?? 1),
            (int) ($request->get('limit') ?? 20),
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->maps->learnerGetMap(
            $this->optionalLearnerId($request),
            $this->id($id),
        ));
    }

    public function start(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->maps->learnerStart(
            $this->requiredLearnerId($request),
            $this->id($id),
        ));
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function optionalLearnerId(Request $request): ?int
    {
        $value = (int) ($request->account_id ?? 0);
        return $value > 0 ? $value : null;
    }

    private function requiredLearnerId(Request $request): int
    {
        $value = $this->optionalLearnerId($request);
        if ($value === null) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $value;
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        $n = (int) $raw;
        if ($n <= 0) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return $n;
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn(), request()->request_id ?? null);
        } catch (BusinessException $e) {
            return ApiResponse::fail(
                $this->mapApiCode($e->apiCode),
                $e->getMessage(),
                request()->request_id ?? null,
            );
        } catch (\Throwable $e) {
            Logger::error('map.learner.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND'       => ApiResponse::NOT_FOUND,
            'FORBIDDEN'       => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT'        => ApiResponse::CONFLICT,
            default           => ApiResponse::VALIDATION_FAILED,
        };
    }
}
