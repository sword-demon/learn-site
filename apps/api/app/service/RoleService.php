<?php

declare(strict_types=1);

namespace App\service;

use App\model\Role;
use App\support\ApiResponse;
use App\support\Logger;
use support\think\Db;

/**
 * Role CRUD + permission binding + data-scope materialization.
 *
 * Spec §FR-080: data_scope ∈ {all, dept_and_children, specified_depts, dept, self}.
 * specified_depts is a flat list of department ids (does NOT include
 * children) per §边界场景 §数据范围.
 *
 * Roles are never deleted if any staff or post still references them; we
 * disable instead so audit logs stay meaningful.
 */
final class RoleService
{
    private const SCOPES = ['all', 'dept_and_children', 'specified_depts', 'dept', 'self'];

    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        $rows = Db::name('roles')->order('id', 'asc')->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = $this->shape($r, $this->permissionIds((int) $r['id']), $this->scopeDeptIds((int) $r['id']));
        }
        return $out;
    }

    /** @return list<array<string,mixed>> */
    public function listPermissions(): array
    {
        $rows = Db::name('permissions')->order('module', 'asc')->order('id', 'asc')->select()->toArray();
        return array_map(fn($r) => [
            'id'          => (int) $r['id'],
            'code'        => (string) $r['code'],
            'module'      => (string) $r['module'],
            'description' => (string) ($r['description'] ?? ''),
        ], is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?Role
    {
        return Role::find($id);
    }

    /**
     * @param list<int> $permissionIds
     * @param list<int> $scopeDepartmentIds
     * @return array<string, mixed>
     */
    public function create(
        string $name,
        string $code,
        string $dataScope,
        array $permissionIds,
        array $scopeDepartmentIds,
        int $actorId,
    ): array {
        $name = trim($name);
        $code = trim($code);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_NAME_INVALID');
        }
        if ($code === '' || !preg_match('/^[a-z][a-z0-9_.\-]{1,63}$/', $code)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_CODE_INVALID');
        }
        if (!in_array($dataScope, self::SCOPES, true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_SCOPE_INVALID');
        }
        if (Db::name('roles')->where('code', $code)->find()) {
            throw new BusinessException(ApiResponse::CONFLICT, 'ROLE_CODE_TAKEN');
        }
        $permissionIds = self::uniqueIds($permissionIds);
        $scopeDepartmentIds = $dataScope === 'specified_depts'
            ? self::uniqueIds($scopeDepartmentIds)
            : [];
        $this->assertPermissionsExist($permissionIds);
        $this->assertDepartmentsExist($scopeDepartmentIds);
        $this->assertActorCanAssignPermissions($permissionIds, $actorId);

        $now = date('Y-m-d H:i:s');
        $id = 0;
        Db::transaction(function () use (
            &$id,
            $name,
            $code,
            $dataScope,
            $permissionIds,
            $scopeDepartmentIds,
            $actorId,
            $now,
        ): void {
            $id = (int) Role::create([
                'name'       => $name,
                'code'       => $code,
                'data_scope' => $dataScope,
                'status'     => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ])->id;
            $this->bindPermissions($id, $permissionIds);
            $this->bindScopeDepartments($id, $scopeDepartmentIds);
            Logger::info('role.created', ['id' => $id, 'code' => $code, 'actor_id' => $actorId]);
        });
        return $this->shape(Role::find($id)->toArray(), $permissionIds, $scopeDepartmentIds);
    }

    /**
     * @param list<int>|null $permissionIds
     * @param list<int>|null $scopeDepartmentIds
     * @return array<string, mixed>
     */
    public function update(
        int $id,
        ?string $name,
        ?string $dataScope,
        ?array $permissionIds,
        ?array $scopeDepartmentIds,
        int $actorId,
    ): array {
        $row = Role::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'ROLE_NOT_FOUND');
        }
        if ($dataScope !== null && !in_array($dataScope, self::SCOPES, true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_SCOPE_INVALID');
        }
        if ($permissionIds !== null) {
            $permissionIds = self::uniqueIds($permissionIds);
            $this->assertPermissionsExist($permissionIds);
        }
        if ($scopeDepartmentIds !== null) {
            $scopeDepartmentIds = self::uniqueIds($scopeDepartmentIds);
            $this->assertDepartmentsExist($scopeDepartmentIds);
        }
        $effectiveScope = $dataScope ?? (string) $row->data_scope;
        if (
            $effectiveScope !== 'specified_depts'
            && ($dataScope !== null || $scopeDepartmentIds !== null)
        ) {
            $scopeDepartmentIds = [];
        }
        $changesAuthority = $dataScope !== null || $permissionIds !== null || $scopeDepartmentIds !== null;
        if (
            $changesAuthority
            && !$this->permissions->isSuperAdmin($actorId)
            && $this->isRoleEffectiveForStaff($id, $actorId)
        ) {
            throw new BusinessException(ApiResponse::FORBIDDEN, 'SELF_GUARD');
        }
        if ($permissionIds !== null) {
            $this->assertActorCanAssignPermissions($permissionIds, $actorId);
        }
        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 64) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_NAME_INVALID');
            }
            $patch['name'] = $name;
        }
        if ($dataScope !== null) {
            $patch['data_scope'] = $dataScope;
        }
        Db::transaction(function () use ($id, $patch, $permissionIds, $scopeDepartmentIds) {
            // ponytail: previous branches were identical; update unconditionally.
            Role::where('id', $id)->update($patch);
            if ($permissionIds !== null) {
                Db::name('role_permission')->where('role_id', $id)->delete();
                $this->bindPermissions($id, $permissionIds);
            }
            if ($scopeDepartmentIds !== null) {
                Db::name('role_scope_department')->where('role_id', $id)->delete();
                $this->bindScopeDepartments($id, $scopeDepartmentIds);
            }
        });
        Logger::info('role.updated', ['id' => $id, 'actor_id' => $actorId]);
        $row = Role::find($id)->toArray();
        return $this->shape(
            $row,
            $permissionIds ?? $this->permissionIds($id),
            $scopeDepartmentIds ?? $this->scopeDeptIds($id),
        );
    }

    /** @return array<string, mixed> */
    public function setStatus(int $id, string $status, int $actorId): array
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_STATUS_INVALID');
        }
        $row = Role::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'ROLE_NOT_FOUND');
        }
        Role::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::info('role.status_changed', ['id' => $id, 'status' => $status, 'actor_id' => $actorId]);
        return $this->shape(Role::find($id)->toArray(), $this->permissionIds($id), $this->scopeDeptIds($id));
    }

    public function delete(int $id, int $actorId): void
    {
        $row = Role::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'ROLE_NOT_FOUND');
        }
        $staffRefs = (int) Db::name('staff_role')->where('role_id', $id)->count();
        $postRefs = (int) Db::name('post_role')->where('role_id', $id)->count();
        if ($staffRefs + $postRefs > 0) {
            throw new BusinessException(ApiResponse::CONFLICT, 'ROLE_IN_USE');
        }
        Db::transaction(function () use ($id) {
            Db::name('role_permission')->where('role_id', $id)->delete();
            Db::name('role_scope_department')->where('role_id', $id)->delete();
            Role::where('id', $id)->delete();
        });
        Logger::info('role.deleted', ['id' => $id, 'actor_id' => $actorId]);
    }

    /** @param list<int> $permissionIds */
    private function bindPermissions(int $roleId, array $permissionIds): void
    {
        $seen = [];
        foreach ($permissionIds as $pid) {
            $pid = (int) $pid;
            if (isset($seen[$pid]) || $pid <= 0) {
                continue;
            }
            $seen[$pid] = true;
            Db::name('role_permission')->insert([
                'role_id'       => $roleId,
                'permission_id' => $pid,
            ]);
        }
    }

    /** @param list<int> $deptIds */
    private function bindScopeDepartments(int $roleId, array $deptIds): void
    {
        $seen = [];
        foreach ($deptIds as $did) {
            $did = (int) $did;
            if (isset($seen[$did]) || $did <= 0) {
                continue;
            }
            $seen[$did] = true;
            Db::name('role_scope_department')->insert([
                'role_id'       => $roleId,
                'department_id' => $did,
            ]);
        }
    }

    /** @param list<int> $ids */
    private function assertPermissionsExist(array $ids): void
    {
        $clean = [];
        foreach ($ids as $i) {
            $clean[(int) $i] = true;
        }
        unset($clean[0]);
        if ($clean === []) {
            return;
        }
        $count = (int) Db::name('permissions')->whereIn('id', array_keys($clean))->count();
        if ($count !== count($clean)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_PERMISSION_INVALID');
        }
    }

    /** @param list<int> $permissionIds */
    private function assertActorCanAssignPermissions(array $permissionIds, int $actorId): void
    {
        if ($permissionIds === []) {
            return;
        }
        $rows = Db::name('permissions')->whereIn('id', $permissionIds)->field('code')->select()->toArray();
        $codes = array_map(
            static fn(array $row): string => (string) $row['code'],
            is_array($rows) ? $rows : [],
        );
        OrgPolicy::assertAssignablePermissionCodes(
            $this->permissions->isSuperAdmin($actorId),
            $this->permissions->effectiveCodes($actorId),
            $codes,
        );
    }

    private function isRoleEffectiveForStaff(int $roleId, int $staffId): bool
    {
        if (Db::name('staff_role')->where('staff_user_id', $staffId)->where('role_id', $roleId)->count() > 0) {
            return true;
        }
        return Db::name('staff_post')->alias('sp')
            ->join('post_role pr', 'pr.post_id = sp.post_id')
            ->where('sp.staff_user_id', $staffId)
            ->where('pr.role_id', $roleId)
            ->count() > 0;
    }

    /** @param list<int> $ids */
    private function assertDepartmentsExist(array $ids): void
    {
        $clean = [];
        foreach ($ids as $i) {
            $clean[(int) $i] = true;
        }
        unset($clean[0]);
        if ($clean === []) {
            return;
        }
        $count = (int) Db::name('departments')->whereIn('id', array_keys($clean))->count();
        if ($count !== count($clean)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'ROLE_SCOPE_DEPT_INVALID');
        }
    }

    /** @return list<int> */
    private function permissionIds(int $roleId): array
    {
        $rows = Db::name('role_permission')->where('role_id', $roleId)->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = (int) $r['permission_id'];
        }
        return $out;
    }

    /** @return list<int> */
    private function scopeDeptIds(int $roleId): array
    {
        $rows = Db::name('role_scope_department')->where('role_id', $roleId)->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $r) {
            $out[] = (int) $r['department_id'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $r
     * @param list<int> $permissionIds
     * @param list<int> $scopeDeptIds
     * @return array<string, mixed>
     */
    private function shape(array $r, array $permissionIds, array $scopeDeptIds): array
    {
        return [
            'id'                  => (int) $r['id'],
            'name'                => (string) $r['name'],
            'code'                => (string) $r['code'],
            'data_scope'          => (string) $r['data_scope'],
            'status'              => (string) $r['status'],
            'permission_ids'      => $permissionIds,
            'scope_department_ids' => $scopeDeptIds,
            'created_at'          => (string) ($r['created_at'] ?? ''),
            'updated_at'          => (string) ($r['updated_at'] ?? ''),
        ];
    }

    /**
     * @param array<array-key, mixed> $ids
     * @return list<int>
     */
    private static function uniqueIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $out[$id] = true;
            }
        }
        return array_keys($out);
    }
}
