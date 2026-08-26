<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\DepartmentService;
use App\support\ApiResponse;
use support\Request;

/**
 * Admin department CRUD.
 *
 * Endpoints (all under /api/admin/v1/departments; Authorize forces
 * org.department):
 *   GET    /departments         — flat list with all statuses
 *   POST   /departments         — { parent_id, name, sort?, status? }
 *   PATCH  /departments/{id}    — { name?, sort? }
 *   PATCH  /departments/{id}/status — { status }
 *   DELETE /departments/{id}
 */
final class DepartmentController
{
    public function __construct(
        private readonly DepartmentService $svc,
    ) {
    }

    public function index(Request $request): \support\Response
    {
        return ApiResponse::ok(['items' => $this->svc->listAll()]);
    }

    public function create(Request $request): \support\Response
    {
        try {
            $body = self::readJson($request);
            $row = $this->svc->create(
                name: (string) ($body['name'] ?? ''),
                parentId: (int) ($body['parent_id'] ?? 0),
                sort: (int) ($body['sort'] ?? 0),
                status: (string) ($body['status'] ?? 'enabled'),
            );
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function update(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $body = self::readJson($request);
            $row = $this->svc->update(
                id: (int) $id,
                name: array_key_exists('name', $body) ? (string) $body['name'] : null,
                sort: array_key_exists('sort', $body) ? (int) $body['sort'] : null,
            );
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function status(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $body = self::readJson($request);
            $row = $this->svc->setStatus((int) $id, (string) ($body['status'] ?? ''));
            return ApiResponse::ok($row);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function destroy(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $this->svc->delete((int) $id);
            return ApiResponse::ok(['deleted' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    /** @return array<array-key, mixed> */
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
