<?php

declare(strict_types=1);

namespace App\controller\learner;

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

    public function store(Request $request, string $courseId): \support\Response
    {
        $body = self::readJson($request);
        return $this->wrapCreated(fn (): array => $this->feedback->submit(
            (int) ($request->account_id ?? 0),
            $this->id($courseId),
            (string) ($body['body_html'] ?? ''),
        ));
    }

    private function id(string $id): int
    {
        if (!ctype_digit($id)) {
            throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
        }
        return (int) $id;
    }

    private function wrapCreated(callable $operation): \support\Response
    {
        try {
            return ApiResponse::ok($operation(), request()->request_id ?? null)->withStatus(201);
        } catch (BusinessException $exception) {
            return $this->fail($exception);
        } catch (\Throwable $exception) {
            Logger::error('course_feedback.submit.failed', ['err' => $exception->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL', request()->request_id ?? null);
        }
    }

    private function fail(BusinessException $exception): \support\Response
    {
        return ApiResponse::fail(
            match ($exception->apiCode) {
                'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
                'NOT_FOUND' => ApiResponse::NOT_FOUND,
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
