<?php

declare(strict_types=1);

namespace App\controller\admin;

use App\service\BusinessException;
use App\service\PermissionService;
use App\service\StaffService;
use App\service\TokenService;
use App\support\ApiResponse;
use App\support\Logger;
use JsonException;
use support\Request;
use support\think\Db;

/**
 * Admin staff CRUD + per-staff override management.
 *
 * Endpoints (Authorize forces org.staff on /staff, org.grant on the
 * overrides endpoint):
 *   GET    /staff                — paginated list, optional ?status=
 *   GET    /staff/{id}           — single row + role/post/overrides
 *   POST   /staff                — credentials and organization fields
 *   PATCH  /staff/{id}           — organization and password fields
 *   PATCH  /staff/{id}/status    — { status }
 *   DELETE /staff/{id}
 *   PUT    /staff/{id}/overrides — [{ code, effect }]   (org.grant)
 *   POST   /staff/{id}/kick      — { family_id? }       (org.staff; handled here too)
 */
final class StaffController
{
    public function __construct(
        private readonly StaffService $staff,
        private readonly PermissionService $perms,
        private readonly TokenService $tokens,
    ) {
    }

    public function index(Request $request): \support\Response
    {
        $status = (string) $request->get('status', '');
        $search = trim((string) $request->get('search', ''));
        $limitParam = $request->get('limit');
        $limit = $limitParam !== null && $limitParam !== '' ? (int) $limitParam : null;
        $page = max(1, (int) $request->get('page', 1));
        return ApiResponse::ok($this->staff->list(
            $status !== '' ? $status : null,
            $search !== '' ? $search : null,
            $page,
            $limit,
            (int) ($request->account_id ?? 0),
        ));
    }

    public function show(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $row = $this->staff->row((int) $id);
            if ($row === []) {
                return ApiResponse::fail(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
            }
            return ApiResponse::ok([
                'staff'     => $row,
                'roles'     => $this->roleIds((int) $id),
                'posts'     => $this->postIds((int) $id),
                'overrides' => $this->staff->overridesOf((int) $id),
            ]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function create(Request $request): \support\Response
    {
        try {
            $body = self::readJson($request);
            $actor = (int) ($request->account_id ?? 0);
            $row = $this->staff->create(
                login: (string) ($body['login'] ?? ''),
                password: (string) ($body['password'] ?? ''),
                displayName: (string) ($body['display_name'] ?? ''),
                isSuperAdmin: (bool) ($body['is_super_admin'] ?? false),
                departmentId: isset($body['department_id']) && $body['department_id'] !== null
                    ? (int) $body['department_id'] : null,
                roleIds: self::ints($body['role_ids'] ?? []),
                postIds: self::ints($body['post_ids'] ?? []),
                actorId: $actor,
                actorIsSuperAdmin: $this->perms->isSuperAdmin($actor),
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
            $actor = (int) ($request->account_id ?? 0);
            $row = $this->staff->update(
                id: (int) $id,
                displayName: array_key_exists('display_name', $body) ? (string) $body['display_name'] : null,
                isSuperAdmin: array_key_exists('is_super_admin', $body) ? (bool) $body['is_super_admin'] : null,
                departmentId: array_key_exists('department_id', $body)
                    ? ($body['department_id'] === null ? 0 : (int) $body['department_id'])
                    : null,
                roleIds: array_key_exists('role_ids', $body) ? self::ints($body['role_ids']) : null,
                postIds: array_key_exists('post_ids', $body) ? self::ints($body['post_ids']) : null,
                resetPassword: (bool) ($body['reset_password'] ?? false),
                newPassword: isset($body['new_password']) ? (string) $body['new_password'] : null,
                actorId: $actor,
                actorIsSuperAdmin: $this->perms->isSuperAdmin($actor),
                actorAccountId: $actor,
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
            $actor = (int) ($request->account_id ?? 0);
            $row = $this->staff->setStatus(
                (int) $id,
                (string) ($request->rawBody() === '' ? '' : self::readJson($request)['status'] ?? ''),
                $actor,
                $actor,
            );
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
            $actor = (int) ($request->account_id ?? 0);
            $this->staff->delete((int) $id, $actor, $actor);
            return ApiResponse::ok(['deleted' => true]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    public function overrides(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        try {
            $entries = self::readOverrideEntries($request);
            $actor = (int) ($request->account_id ?? 0);
            $out = $this->staff->setOverrides(
                (int) $id,
                $entries,
                $actor,
            );
            return ApiResponse::ok(['overrides' => $out]);
        } catch (BusinessException $e) {
            return ApiResponse::fail($e->apiCode, $e->getMessage());
        }
    }

    /** @return list<array<string,mixed>> */
    private static function readOverrideEntries(Request $request): array
    {
        $raw = trim((string) $request->rawBody());
        if ($raw === '') {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_ENTRIES_REQUIRED');
        }

        try {
            $body = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'INVALID_JSON');
        }

        if (!is_array($body) || !array_key_exists('entries', $body) || !is_array($body['entries'])) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_ENTRIES_REQUIRED');
        }

        return $body['entries'];
    }

    public function kick(Request $request, string $id): \support\Response
    {
        if (!ctype_digit($id) || (int) $id <= 0) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_ID');
        }
        $body = self::readJson($request);
        $family = $body['family_id'] ?? null;
        if ($family !== null && !is_string($family)) {
            return ApiResponse::fail(ApiResponse::VALIDATION_FAILED, 'INVALID_FAMILY');
        }
        if ($family) {
            $count = $this->tokens->kickFamily($id, $family);
            Logger::info('staff.kick.family', [
                'target_id' => (int) $id,
                'family_id' => $family,
                'actor_id' => (int) ($request->account_id ?? 0),
                'revoked' => $count,
            ]);
        } else {
            $count = $this->tokens->kickAll($id);
            Logger::info('staff.kick.all', [
                'target_id' => (int) $id,
                'actor_id' => (int) ($request->account_id ?? 0),
                'revoked' => $count,
            ]);
        }
        return ApiResponse::ok(['revoked' => $count]);
    }

    /** @return list<int> */
    private function roleIds(int $staffId): array
    {
        $rows = Db::name('staff_role')->where('staff_user_id', $staffId)->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = (int) $r['role_id'];
        }
        return $out;
    }

    /** @return list<int> */
    private function postIds(int $staffId): array
    {
        $rows = Db::name('staff_post')->where('staff_user_id', $staffId)->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = (int) $r['post_id'];
        }
        return $out;
    }

    /** @return list<int> */
    private static function ints(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $v) {
            if (is_int($v) || (is_string($v) && ctype_digit($v))) {
                $out[] = (int) $v;
            }
        }
        return $out;
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
