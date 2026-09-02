<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CourseFeedbackService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

final class CourseFeedbackController
{
    public function __construct(private readonly CourseFeedbackService $feedback)
    {
    }

    public function index(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(fn (): array => $this->feedback->listFeedbacks(
            (int) ($request->account_id ?? 0),
            $this->id($courseId),
            [
                'status' => (string) ($request->get('status', '') ?? ''),
                'page' => (int) $request->get('page', 1),
                'limit' => (int) $request->get('limit', 20),
            ],
        ));
    }

    public function show(Request $request, string $courseId, string $feedbackId): \support\Response
    {
        return $this->wrap(fn (): array => $this->feedback->getFeedback(
            (int) ($request->account_id ?? 0),
            $this->id($courseId),
            $this->id($feedbackId),
        ));
    }

    public function update(Request $request, string $courseId, string $feedbackId): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrap(fn (): array => $this->feedback->updateStatus(
            (int) ($request->account_id ?? 0),
            $this->id($courseId),
            $this->id($feedbackId),
            (string) ($body['status'] ?? ''),
        ));
    }

    private function id(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    private function wrap(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('course_feedback.admin.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail(
            match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
                'CONFLICT' => ApiResponse::CONFLICT,
                'FORBIDDEN' => ApiResponse::FORBIDDEN,
                default => ApiResponse::VALIDATION_FAILED,
            },
            $exception->getMessage(),
            request()->request_id ?? null,
        );
    }

    /** @return array<string, mixed> */
    private static function readJson(Request $request): array
    {
        $decoded = json_decode((string) $request->rawBody(), true);
        return is_array($decoded) ? $decoded : [];
    }
}
