<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\QuestionService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Admin Q&A inbox (Phase 11 / US4).
 *
 *   GET  /api/admin/v1/questions?status=pending|answered|closed
 *   GET  /api/admin/v1/questions/filter-options?course_id={id}
 *   GET  /api/admin/v1/questions/{id}
 *   POST /api/admin/v1/questions/{id}/answer  { body }
 *   POST /api/admin/v1/questions/{id}/close
 *
 * Authorization is enforced by the Authorize middleware with `qa.view` for
 * read routes and `qa.answer` for answer/close actions.
 */
final class QuestionController
{
    public function __construct(private readonly QuestionService $questions)
    {
    }

    public function inbox(Request $request): \support\Response
    {
        return $this->wrap(fn () => $this->questions->adminInbox($this->staffId($request), [
            'page'  => (int) ($request->get('page')  ?? 1),
            'limit' => (int) ($request->get('limit') ?? 20),
            'status' => (string) ($request->get('status') ?? 'pending'),
            'course_id' => (int) ($request->get('course_id') ?? 0),
            'lesson_id' => (int) ($request->get('lesson_id') ?? 0),
        ]));
    }

    public function filterOptions(Request $request): \support\Response
    {
        $courseId = (int) ($request->get('course_id') ?? 0);
        return $this->wrap(fn () => $this->questions->adminFilterOptions(
            $this->staffId($request),
            $courseId > 0 ? $courseId : null,
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->questions->adminShow(
            $this->staffId($request),
            $this->id($id),
        ));
    }

    public function answer(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $staffId = $this->staffId($request);
            $body = self::readJson($request);
            return $this->questions->adminAnswer(
                $staffId,
                $this->id($id),
                (string) ($body['body'] ?? ''),
            );
        });
    }

    public function close(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $staffId = $this->staffId($request);
            return $this->questions->adminClose($staffId, $this->id($id));
        });
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
            Logger::error('question.admin.failed', ['err' => $e->getMessage()]);
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
