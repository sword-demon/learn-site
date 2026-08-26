<?php

declare(strict_types=1);

namespace App\controller\learner;

use App\service\BusinessException;
use App\service\QuestionService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Learner Q&A surface (Phase 11 / US4).
 *
 *   GET  /api/learner/v1/lessons/{lessonId}/questions?status=pending|answered|closed
 *   GET  /api/learner/v1/questions/{id}
 *   POST /api/learner/v1/lessons/{lessonId}/questions        { title, body }
 *   POST /api/learner/v1/questions/{id}/messages              { body }
 */
final class QuestionController
{
    public function __construct(private readonly QuestionService $questions)
    {
    }

    public function index(Request $request, string $lessonId): \support\Response
    {
        return $this->wrap(function () use ($request, $lessonId) {
            $aid = $this->aid($request);
            if ($aid === null) {
                throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
            }
            return $this->questions->listForLesson($aid, $this->id($lessonId), [
                'page'  => (int) ($request->get('page')  ?? 1),
                'limit' => (int) ($request->get('limit') ?? 20),
                'status' => (string) ($request->get('status') ?? ''),
            ]);
        });
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $aid = $this->aid($request);
            if ($aid === null) {
                throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
            }
            return $this->questions->showForLearner($aid, $this->id($id));
        });
    }

    public function create(Request $request, string $lessonId): \support\Response
    {
        return $this->wrap(function () use ($request, $lessonId) {
            $aid = $this->aid($request);
            if ($aid === null) {
                throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
            }
            $body = self::readJson($request);
            return $this->questions->askOnLesson(
                $aid,
                $this->id($lessonId),
                (string) ($body['title'] ?? ''),
                (string) ($body['body']  ?? ''),
            );
        });
    }

    public function followup(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $aid = $this->aid($request);
            if ($aid === null) {
                throw new BusinessException('UNAUTHENTICATED', 'UNAUTHENTICATED');
            }
            $body = self::readJson($request);
            return $this->questions->appendLearnerFollowup(
                $aid,
                $this->id($id),
                (string) ($body['body'] ?? ''),
            );
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function aid(Request $request): ?int
    {
        $v = (int) ($request->account_id ?? 0);
        return $v > 0 ? $v : null;
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
            Logger::error('question.learner.failed', ['err' => $e->getMessage()]);
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
