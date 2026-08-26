<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\PostService;
use App\support\ApiResponse;
use support\Request;

/**
 * Admin post CRUD (FR-072: posts are bound to a department).
 *
 * Endpoints (all under /api/admin/v1/posts; Authorize forces org.post):
 *   GET    /posts            — flat list, optional ?department_id=&status=
 *   POST   /posts            — { department_id, name, status? }
 *   PATCH  /posts/{id}       — { name?, status? }
 *   DELETE /posts/{id}
 *
 * NOTE: No setStatus/separate route — `status` rides in PATCH for simplicity.
 */
final class PostController
{
    public function __construct(private readonly PostService $posts)
    {
    }

    public function index(Request $request): \support\Response
    {
        $dept = (string) $request->get('department_id', '');
        $status = (string) $request->get('status', '');
        return ApiResponse::ok([
            'items' => $this->posts->listAll(
                $dept !== '' && ctype_digit($dept) ? (int) $dept : null,
                $status !== '' ? $status : null,
            ),
        ]);
    }

    public function create(Request $request): \support\Response
    {
        try {
            $body = self::readJson($request);
            return ApiResponse::ok($this->posts->create(
                (int) ($body['department_id'] ?? 0),
                (string) ($body['name'] ?? ''),
                (string) ($body['status'] ?? 'enabled'),
                self::ints($body['role_ids'] ?? []),
                (int) ($request->account_id ?? 0),
            ));
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
            return ApiResponse::ok($this->posts->update(
                (int) $id,
                array_key_exists('name', $body) ? (string) $body['name'] : null,
                array_key_exists('status', $body) ? (string) $body['status'] : null,
                array_key_exists('role_ids', $body) ? self::ints($body['role_ids']) : null,
                (int) ($request->account_id ?? 0),
            ));
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
            $this->posts->delete((int) $id, (int) ($request->account_id ?? 0));
            return ApiResponse::ok(['deleted' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    /** @return list<int> */
    private static function ints(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        return array_values(array_map('intval', array_filter(
            $raw,
            static fn(mixed $value): bool => is_int($value) || (is_string($value) && ctype_digit($value)),
        )));
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
