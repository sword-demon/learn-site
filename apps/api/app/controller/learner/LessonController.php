<?php
declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\PublicLessonService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner-facing lesson delivery (Phase 5 / US1).
 *
 * Route (auth required):
 *
 *   GET /api/learner/v1/courses/{id}/lessons/{lessonId}
 *
 * Preview lessons are open to anyone (no entitlement check); full
 * lessons hard-fail with 403 until Phase 6 plugs EntitlementService in.
 */
final class LessonController
{
    public function __construct(private readonly PublicLessonService $lessons) {}

    public function deliver(Request $request, string $courseId, string $lessonId): \support\Response
    {
        return $this->wrap(fn() => $this->lessons->deliver(
            $this->id($courseId),
            $this->id($lessonId),
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
            Logger::error('public.lesson.failed', [
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
