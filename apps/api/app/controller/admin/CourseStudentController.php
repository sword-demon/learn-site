<?php
declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\CourseStudentService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Phase 18 / US8 — Course student list (T091).
 *
 *   GET   /api/admin/v1/courses/{courseId}/students?status=&page=&limit=
 *   POST  /api/admin/v1/courses/{courseId}/students/{accountId}/revoke
 *
 * Lists learners holding an entitlement to the given course. Permission is
 * `course_student.view` (Authorize middleware). The actor's department scope
 * is honoured: if DataScopeService.allowedDepartmentIds() returns a list,
 * rows outside that set are filtered out. The revoke endpoint is gated on
 * `course_student.revoke_free`.
 */
final class CourseStudentController
{
    public function __construct(
        private readonly CourseStudentService $service,
    ) {}

    public function index(Request $request, string $courseId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId) {
            if (!ctype_digit($courseId)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            return $this->service->listForCourse($this->staffId($request), (int) $courseId, [
                'status' => $request->get('status'),
                'source' => $request->get('source'),
                'learning_status' => $request->get('learning_status'),
                'page' => $request->get('page', 1),
                'limit' => $request->get('limit', 20),
            ]);
        });
    }

    /**
     * Phase 21 / US18 — revoke a free entitlement (T102).
     *
     *   POST /api/admin/v1/courses/{courseId}/students/{accountId}/revoke
     *
     * Body: { "reason": "..." } (required).
     *
     * US18 rule: only `source='free'` rows are revocable here. Paid
     * entitlements require a refund workflow that is out of scope for the
     * first release — the operator will see 403 PAID_NOT_REVOCABLE in that
     * case.
     */
    public function revoke(Request $request, string $courseId, string $accountId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId, $accountId) {
            if (!ctype_digit($courseId) || !ctype_digit($accountId)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            $body = self::readJson($request);
            return $this->service->revokeFree(
                $this->staffId($request),
                (int) $courseId,
                (int) $accountId,
                (string) ($body['reason'] ?? ''),
            );
        });
    }

    public function resetProgress(Request $request, string $courseId, string $accountId): \support\Response
    {
        return $this->wrap(function () use ($request, $courseId, $accountId) {
            if (!ctype_digit($courseId) || !ctype_digit($accountId)) {
                throw new BusinessException('VALIDATION_FAILED', 'INVALID_ID');
            }
            return $this->service->resetProgress(
                $this->staffId($request),
                (int) $courseId,
                (int) $accountId,
            );
        });
    }

    // ─── helpers ─────────────────────────────────────────────────────

    private function staffId(Request $request): int
    {
        $v = (int) ($request->account_id ?? 0);
        if ($v <= 0) {
            throw new \RuntimeException('UNAUTHENTICATED');
        }
        return $v;
    }

    private function wrap(callable $fn): \support\Response
    {
        try {
            return ApiResponse::ok($fn());
        } catch (BusinessException $e) {
            return ApiResponse::fail($this->mapApiCode($e->apiCode), $e->getMessage());
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'UNAUTHENTICATED') {
                return ApiResponse::fail(ApiResponse::UNAUTHENTICATED, 'UNAUTHENTICATED');
            }
            throw $e;
        } catch (\Throwable $e) {
            Logger::error('course_students.failed', ['err' => $e->getMessage()]);
            return ApiResponse::fail(ApiResponse::INTERNAL, 'INTERNAL');
        }
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

    private function mapApiCode(string $code): string
    {
        return match ($code) {
            'UNAUTHENTICATED' => ApiResponse::UNAUTHENTICATED,
            'NOT_FOUND' => ApiResponse::NOT_FOUND,
            'FORBIDDEN' => ApiResponse::FORBIDDEN,
            'VALIDATION_FAILED' => ApiResponse::VALIDATION_FAILED,
            'CONFLICT' => ApiResponse::CONFLICT,
            default => ApiResponse::VALIDATION_FAILED,
        };
    }
}
