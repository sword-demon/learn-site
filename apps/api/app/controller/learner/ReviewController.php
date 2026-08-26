<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\ReviewService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner-facing reviews + replies (Phase 12 / US5).
 *
 *   GET  /api/learner/v1/courses/{id}/reviews
 *   POST /api/learner/v1/courses/{id}/reviews  { rating, body }
 *   GET  /api/learner/v1/reviews/{id}
 *   PATCH/DELETE /api/learner/v1/reviews/{id}
 *   POST /api/learner/v1/reviews/{id}/replies  { body, parent_id? }
 */
final class ReviewController
{
    public function __construct(private readonly ReviewService $reviews)
    {
    }

    public function list(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(fn () => $this->reviews->listForCourse(
            $this->id($courseId),
            (int) ($request->get('page') ?? 1),
            (int) ($request->get('limit') ?? 20),
            $this->viewerAccountId($request),
        ));
    }

    public function post(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId) {
            $learnerId = $this->viewerId($request);
            $body = self::readJson($request);
            return $this->reviews->postReview(
                $learnerId,
                $this->id($courseId),
                (int) ($body['rating'] ?? 0),
                (string) ($body['body'] ?? ''),
            );
        });
    }

    public function thread(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->reviews->showThread(
            $this->id($id),
            $this->viewerAccountId($request),
        ));
    }

    public function update(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $body = self::readJson($request);
            return $this->reviews->updateReview(
                $this->viewerId($request),
                $this->id($id),
                (int) ($body['rating'] ?? 0),
                (string) ($body['body'] ?? ''),
            );
        });
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id): array {
            $this->reviews->deleteReview($this->viewerId($request), $this->id($id));
            return ['deleted' => true];
        });
    }

    public function reply(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $learnerId = $this->viewerId($request);
            $body = self::readJson($request);
            $parent = isset($body['parent_id']) && $body['parent_id'] !== null
                ? (int) $body['parent_id']
                : null;
            return $this->reviews->replyAsLearner(
                $learnerId,
                $this->id($id),
                $parent,
                (string) ($body['body'] ?? ''),
            );
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

    private function viewerAccountId(Request $request): ?int
    {
        $viewerId = (int) ($request->account_id ?? 0);
        return $viewerId > 0 ? $viewerId : null;
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
            Logger::error('review.learner.failed', ['err' => $e->getMessage()]);
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
