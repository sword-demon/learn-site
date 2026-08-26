<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\PublicCatalogService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner-facing catalog (Phase 5 / US1).
 *
 * Routes (public — auth optional, Account id injected when present):
 *
 *   GET /api/learner/v1/categories/{id}/courses
 *   GET /api/learner/v1/courses/{id}
 */
final class CatalogController
{
    public function __construct(private readonly PublicCatalogService $catalog) {}

    public function coursesByCategory(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => [
            'category' => $this->catalog->categoryBreadcrumb($this->id($id)),
            'list'     => $this->catalog->coursesByCategory(
                $this->id($id),
                $this->viewerAccountId($request),
                [
                    'page'  => (int) $request->get('page', 1),
                    'limit' => (int) $request->get('limit', 20),
                ],
            ),
        ]);
    }

    public function courseDetail(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn() => $this->catalog->courseDetail(
            $this->id($id),
            $this->viewerAccountId($request),
        ));
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function viewerAccountId(Request $request): ?int
    {
        $aid = (int) ($request->account_id ?? 0);
        return $aid > 0 ? $aid : null;
    }

    private function id(string $raw): int
    {
        if (!ctype_digit($raw)) {
            throw new BusinessException('NOT_FOUND', 'INVALID_ID');
        }
        $n = (int) $raw;
        if ($n <= 0) {
            throw new BusinessException('NOT_FOUND', 'INVALID_ID');
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
            Logger::error('public.catalog.failed', [
                'err' => $e->getMessage(),
                'class' => $e::class,
            ]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'NOT_FOUND'       => ApiResponse::NOT_FOUND,
            'FORBIDDEN'       => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            default           => ApiResponse::VALIDATION_FAILED,
        };
    }
}
