<?php

declare(strict_types=1);

namespace App\service;

use App\model\Department;
use App\support\ApiResponse;
use App\support\Logger;
use support\think\Db;

final class PostService
{
    public function __construct(private readonly PermissionService $permissions)
    {
    }

    /** @return list<array<string,mixed>> */
    public function listAll(?int $departmentId, ?string $status): array
    {
        $query = Db::name('posts')->alias('p')
            ->leftJoin('departments d', 'd.id = p.department_id')
            ->field('p.id, p.department_id, p.name, p.status, p.created_at, p.updated_at, d.name AS department_name');
        if ($departmentId !== null && $departmentId > 0) {
            $query->where('p.department_id', $departmentId);
        }
        if (in_array($status, ['enabled', 'disabled'], true)) {
            $query->where('p.status', $status);
        }
        $rows = $query->order('p.id', 'asc')->select()->toArray();
        $items = is_array($rows) ? $rows : [];
        $roleIds = $this->roleIdsByPost(array_map(static fn(array $row): int => (int) $row['id'], $items));
        return array_map(
            fn(array $row): array => $this->shape($row, $roleIds[(int) $row['id']] ?? []),
            $items,
        );
    }

    /**
     * @param list<int> $roleIds
     * @return array<string, mixed>
     */
    public function create(
        int $departmentId,
        string $name,
        string $status,
        array $roleIds,
        int $actorId,
    ): array {
        $this->assertDepartment($departmentId);
        $name = $this->validatedName($name);
        $this->assertStatus($status);
        $roleIds = self::uniqueIds($roleIds);
        $this->assertRolesAssignable($roleIds, $actorId);

        $now = date('Y-m-d H:i:s');
        $id = 0;
        Db::transaction(function () use (&$id, $departmentId, $name, $status, $roleIds, $now): void {
            $id = (int) Db::name('posts')->insertGetId([
                'department_id' => $departmentId,
                'name' => $name,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->bindRoles($id, $roleIds);
        });
        Logger::info('post.created', ['id' => $id, 'department_id' => $departmentId, 'actor_id' => $actorId]);
        return $this->loadRow($id);
    }

    /**
     * @param list<int>|null $roleIds
     * @return array<string, mixed>
     */
    public function update(
        int $id,
        ?string $name,
        ?string $status,
        ?array $roleIds,
        int $actorId,
    ): array {
        $row = Db::name('posts')->where('id', $id)->find();
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'POST_NOT_FOUND');
        }
        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if ($name !== null) {
            $patch['name'] = $this->validatedName($name);
        }
        if ($status !== null) {
            $this->assertStatus($status);
            $patch['status'] = $status;
        }
        $normalizedRoleIds = $roleIds !== null ? self::uniqueIds($roleIds) : null;
        if ($normalizedRoleIds !== null) {
            if (
                !$this->permissions->isSuperAdmin($actorId)
                && Db::name('staff_post')->where('staff_user_id', $actorId)->where('post_id', $id)->count() > 0
            ) {
                throw new BusinessException(ApiResponse::FORBIDDEN, 'SELF_GUARD');
            }
            $this->assertRolesAssignable($normalizedRoleIds, $actorId);
        }

        Db::transaction(function () use ($id, $patch, $normalizedRoleIds): void {
            Db::name('posts')->where('id', $id)->update($patch);
            if ($normalizedRoleIds !== null) {
                Db::name('post_role')->where('post_id', $id)->delete();
                $this->bindRoles($id, $normalizedRoleIds);
            }
        });
        Logger::info('post.updated', ['id' => $id, 'actor_id' => $actorId]);
        return $this->loadRow($id);
    }

    public function delete(int $id, int $actorId): void
    {
        if (!Db::name('posts')->where('id', $id)->find()) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'POST_NOT_FOUND');
        }
        if ((int) Db::name('staff_post')->where('post_id', $id)->count() > 0) {
            throw new BusinessException(ApiResponse::CONFLICT, 'POST_IN_USE');
        }
        Db::transaction(function () use ($id): void {
            Db::name('post_role')->where('post_id', $id)->delete();
            Db::name('posts')->where('id', $id)->delete();
        });
        Logger::info('post.deleted', ['id' => $id, 'actor_id' => $actorId]);
    }

    private function assertDepartment(int $departmentId): void
    {
        $department = Department::find($departmentId);
        if (!$department || (string) $department->status !== 'enabled') {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'POST_DEPARTMENT_INVALID');
        }
    }

    private function validatedName(string $name): string
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'POST_NAME_INVALID');
        }
        return $name;
    }

    private function assertStatus(string $status): void
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'POST_STATUS_INVALID');
        }
    }

    /** @param list<int> $roleIds */
    private function assertRolesAssignable(array $roleIds, int $actorId): void
    {
        if ($roleIds === []) {
            return;
        }
        $rows = Db::name('roles')->alias('r')
            ->leftJoin('role_permission rp', 'rp.role_id = r.id')
            ->leftJoin('permissions p', 'p.id = rp.permission_id')
            ->whereIn('r.id', $roleIds)
            ->where('r.status', 'enabled')
            ->field('r.id, p.code')
            ->select()
            ->toArray();
        $found = [];
        $codes = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $found[(int) $row['id']] = true;
            if (($row['code'] ?? null) !== null) {
                $codes[] = (string) $row['code'];
            }
        }
        if (count($found) !== count($roleIds)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'POST_ROLE_INVALID');
        }
        OrgPolicy::assertAssignablePermissionCodes(
            $this->permissions->isSuperAdmin($actorId),
            $this->permissions->effectiveCodes($actorId),
            $codes,
        );
    }

    /** @param list<int> $roleIds */
    private function bindRoles(int $postId, array $roleIds): void
    {
        foreach ($roleIds as $roleId) {
            Db::name('post_role')->insert(['post_id' => $postId, 'role_id' => $roleId]);
        }
    }

    /**
     * @param list<int> $postIds
     * @return array<int,list<int>>
     */
    private function roleIdsByPost(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $rows = Db::name('post_role')->whereIn('post_id', $postIds)->order('role_id', 'asc')->select()->toArray();
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[(int) $row['post_id']][] = (int) $row['role_id'];
        }
        return $out;
    }

    /** @return array<string, mixed> */
    private function loadRow(int $id): array
    {
        $row = Db::name('posts')->alias('p')
            ->leftJoin('departments d', 'd.id = p.department_id')
            ->where('p.id', $id)
            ->field('p.id, p.department_id, p.name, p.status, p.created_at, p.updated_at, d.name AS department_name')
            ->find();
        return $row ? $this->shape($row, $this->roleIdsByPost([$id])[$id] ?? []) : [];
    }

    /**
     * @param array<string, mixed> $row
     * @param list<int> $roleIds
     * @return array<string, mixed>
     */
    private function shape(array $row, array $roleIds): array
    {
        return [
            'id' => (int) $row['id'],
            'department_id' => (int) $row['department_id'],
            'department_name' => (string) ($row['department_name'] ?? ''),
            'name' => (string) $row['name'],
            'status' => (string) $row['status'],
            'role_ids' => $roleIds,
            'created_at' => (string) ($row['created_at'] ?? ''),
            'updated_at' => (string) ($row['updated_at'] ?? ''),
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
