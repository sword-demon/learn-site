<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\ReviewService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Admin review moderation (Phase 12 / US5).
 *
 *   GET  /api/admin/v1/reviews?course_id=&visibility=
 *   GET  /api/admin/v1/reviews/filter-options
 *   GET  /api/admin/v1/reviews/{id}
 *   POST /api/admin/v1/reviews/{id}/hide    { reason }
 *   POST /api/admin/v1/reviews/{id}/restore
 *   POST /api/admin/v1/reviews/{id}/replies { body, parent_id? }
 *   POST /api/admin/v1/review-replies/{id}/hide|restore
 *
 * The Authorize middleware requires `review.view` for read/reply endpoints
 * and `review.moderate` for hide/restore actions.
 */
final class ReviewController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    public function list(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $courseId = (int) ($request->get('course_id') ?? 0);
            $visibility = (string) ($request->get('visibility') ?? 'all');
            if ($courseId <= 0) {
                throw new BusinessException('VALIDATION_FAILED', 'COURSE_ID_REQUIRED');
            }
            return $this->reviews->listForModeration(
                $this->staffId($request),
                $courseId,
                $visibility,
                (int) ($request->get('page') ?? 1),
                (int) ($request->get('limit') ?? 20),
            );
        });
    }

    public function filterOptions(Request $request): \support\Response
    {
        return $this->wrap(fn () => $this->reviews->moderationFilterOptions(
            $this->staffId($request),
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->reviews->showForModeration(
            $this->staffId($request),
            $this->id($id),
        ));
    }

    public function hide(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $staffId = $this->staffId($request);
            $body = self::readJson($request);
            return $this->reviews->hideReview(
                $staffId,
                $this->id($id),
                (string) ($body['reason'] ?? ''),
            );
        });
    }

    public function restore(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            return $this->reviews->restoreReview(
                $this->staffId($request),
                $this->id($id),
            );
        });
    }

    public function reply(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $staffId = $this->staffId($request);
            $body = self::readJson($request);
            $parent = isset($body['parent_id']) && $body['parent_id'] !== null
                ? (int) $body['parent_id']
                : null;
            return $this->reviews->replyAsAdmin(
                $staffId,
                $this->id($id),
                $parent,
                (string) ($body['body'] ?? ''),
            );
        });
    }

    public function hideReply(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $body = self::readJson($request);
            return $this->reviews->hideReply(
                $this->staffId($request),
                $this->id($id),
                (string) ($body['reason'] ?? ''),
            );
        });
    }

    public function restoreReply(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->reviews->restoreReply(
            $this->staffId($request),
            $this->id($id),
        ));
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function staffId(Request $request): int
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
            Logger::error('review.admin.failed', ['err' => $e->getMessage()]);
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

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $raw = (string) $request->rawBody();
        if ($raw === '') {
            return [];
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : [];
    }
}
