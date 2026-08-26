<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\LearningMapService;
use App\support\ApiResponse;
use App\support\Logger;
use support\Request;

/**
 * Admin learning-map management (Phase 13 / US6).
 *
 *   GET    /api/admin/v1/learning-maps?department_id=&status=
 *   POST   /api/admin/v1/learning-maps                    { department_id, title, summary? }
 *   GET    /api/admin/v1/learning-maps/{id}
 *   PATCH  /api/admin/v1/learning-maps/{id}               { title?, summary?, department_id? }
 *   DELETE /api/admin/v1/learning-maps/{id}
 *   POST   /api/admin/v1/learning-maps/{id}/publish
 *   POST   /api/admin/v1/learning-maps/{id}/unpublish
 *
 *   POST   /api/admin/v1/learning-maps/{id}/stages        { title, summary? }
 *   PATCH  /api/admin/v1/learning-maps/{id}/stages/{stageId}
 *   DELETE /api/admin/v1/learning-maps/{id}/stages/{stageId}
 *   POST   /api/admin/v1/learning-maps/{id}/stages/{stageId}/courses  { course_id }
 *   DELETE /api/admin/v1/learning-maps/{id}/stages/{stageId}/courses/{courseId}
 */
final class LearningMapController
{
    public function __construct(private readonly LearningMapService $maps)
    {
    }

    public function index(Request $request): \support\Response
    {
        return $this->wrap(fn () => $this->maps->adminListMaps(
            $this->staffId($request),
            [
                'department_id' => $request->get('department_id'),
                'status'        => $request->get('status'),
            ],
            (int) ($request->get('page') ?? 1),
            (int) ($request->get('limit') ?? 20),
        ));
    }

    public function create(Request $request): \support\Response
    {
        return $this->wrap(function () use ($request) {
            $body = self::readJson($request);
            return $this->maps->adminCreateMap($this->staffId($request), $body);
        });
    }

    public function show(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->maps->adminGetMap(
            $this->staffId($request),
            $this->id($id),
        ));
    }

    public function update(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $body = self::readJson($request);
            return $this->maps->adminUpdateMap($this->staffId($request), $this->id($id), $body);
        });
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $this->maps->adminDeleteMap($this->staffId($request), $this->id($id));
            return ['id' => $this->id($id)];
        });
    }

    public function publish(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->maps->adminPublishMap($this->staffId($request), $this->id($id)));
    }

    public function unpublish(Request $request, string $id): \support\Response
    {
        return $this->wrap(fn () => $this->maps->adminUnpublishMap($this->staffId($request), $this->id($id)));
    }

    public function addStage(Request $request, string $id): \support\Response
    {
        return $this->wrap(function () use ($request, $id) {
            $body = self::readJson($request);
            return $this->maps->adminAddStage($this->staffId($request), $this->id($id), $body);
        });
    }

    public function updateStage(Request $request, string $id, string $stageId): \support\Response
    {
        return $this->wrap(function () use ($request, $id, $stageId) {
            $body = self::readJson($request);
            return $this->maps->adminUpdateStage(
                $this->staffId($request),
                $this->id($id),
                $this->id($stageId),
                $body,
            );
        });
    }

    public function deleteStage(Request $request, string $id, string $stageId): \support\Response
    {
        return $this->wrap(function () use ($request, $id, $stageId) {
            $this->maps->adminDeleteStage($this->staffId($request), $this->id($id), $this->id($stageId));
            return ['id' => $this->id($id), 'stage_id' => $this->id($stageId)];
        });
    }

    public function addCourseToStage(Request $request, string $id, string $stageId): \support\Response
    {
        return $this->wrap(function () use ($request, $id, $stageId) {
            $body = self::readJson($request);
            $courseId = (int) ($body['course_id'] ?? 0);
            if ($courseId <= 0) {
                throw new BusinessException('VALIDATION_FAILED', 'COURSE_ID_REQUIRED');
            }
            return $this->maps->adminAddCourseToStage(
                $this->staffId($request),
                $this->id($id),
                $this->id($stageId),
                $courseId,
            );
        });
    }

    public function removeCourseFromStage(
        Request $request,
        string $id,
        string $stageId,
        string $courseId,
    ): \support\Response {
        return $this->wrap(function () use ($request, $id, $stageId, $courseId) {
            $this->maps->adminRemoveCourseFromStage(
                $this->staffId($request),
                $this->id($id),
                $this->id($stageId),
                $this->id($courseId),
            );
            return ['stage_id' => $this->id($stageId), 'course_id' => $this->id($courseId)];
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
            Logger::error('map.admin.failed', ['err' => $e->getMessage()]);
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
