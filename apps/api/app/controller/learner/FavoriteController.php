<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\FavoriteService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class FavoriteController
{
    public function __construct(private readonly FavoriteService $favorites)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $learnerId = $this->viewerId($request);
            $page  = max(1, (int) $request->get('page', 1));
            $limit = max(1, min(100, (int) $request->get('limit', 20)));
            return $this->favorites->list($learnerId, $page, $limit);
        });
    }

    public function add(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $learnerId = $this->viewerId($request);
            $cid = $this->id($id);
            $this->favorites->add($learnerId, $cid);
            return ['course_id' => $cid, 'favorited' => true];
        });
    }

    public function remove(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $learnerId = $this->viewerId($request);
            $cid = $this->id($id);
            $this->favorites->remove($learnerId, $cid);
            return ['course_id' => $cid, 'favorited' => false];
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function viewerId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
        }
        return $v;
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
            Logger::error('favorite.learner.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED'  => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND'        => ApiResponse::NOT_FOUND,
            'FORBIDDEN'        => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT'         => ApiResponse::CONFLICT,
            default            => ApiResponse::VALIDATION_FAILED,
        };
    }
}
