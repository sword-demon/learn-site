<?php

declare(strict_types=1);

namespace App\service;

use App\model\Department;
use App\support\ApiResponse;
use App\support\Logger;
use support\think\Db;

/**
 * Department CRUD with 5-level cap, status, and the rule that disabling a
 * department does NOT cascade to its descendants (spec §边界场景 §组织).
 * Spec already chose materialized path via departments.path; we keep it.
 */
final class DepartmentService
{
    private const MAX_DEPTH = 5;

    /** @return list<array<string,mixed>> */
    public function listAll(): array
    {
        $rows = Db::name('departments')
            ->order('depth', 'asc')
            ->order('sort', 'asc')
            ->order('id', 'asc')
            ->select()
            ->toArray();
        return array_map(fn($r) => $this->shape($r), is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?Department
    {
        return Department::find($id);
    }

    /** @return array<string, mixed> */
    public function create(string $name, int $parentId, int $sort, string $status): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'DEPARTMENT_NAME_INVALID');
        }
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'DEPARTMENT_STATUS_INVALID');
        }
        $depth = 1;
        $parentPath = '/';
        $storedParentId = OrgPolicy::normalizeParentId($parentId);
        if ($parentId > 0) {
            $parent = Department::find($parentId);
            if (!$parent) {
                throw new BusinessException(ApiResponse::NOT_FOUND, 'DEPARTMENT_NOT_FOUND');
            }
            $depth = ((int) $parent->depth) + 1;
            if ($depth > self::MAX_DEPTH) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'DEPARTMENT_DEPTH_EXCEEDED');
            }
            $parentPath = (string) $parent->path;
        }
        $duplicateQuery = Db::name('departments')->where('name', $name);
        if ($storedParentId === null) {
            $duplicateQuery->whereNull('parent_id');
        } else {
            $duplicateQuery->where('parent_id', $storedParentId);
        }
        $dup = $duplicateQuery->find();
        if ($dup) {
            throw new BusinessException(ApiResponse::CONFLICT, 'DEPARTMENT_NAME_TAKEN');
        }
        $now = date('Y-m-d H:i:s');
        $id = (int) Department::create([
            'parent_id'  => $storedParentId,
            'name'       => $name,
            'path'       => $parentPath,
            'depth'      => $depth,
            'sort'       => max(0, $sort),
            'status'     => $status,
            'created_at' => $now,
            'updated_at' => $now,
        ])->id;
        Department::where('id', $id)->update([
            'path' => $parentPath === '/' ? '/' . $id : $parentPath . '/' . $id,
        ]);
        Logger::info('department.created', ['id' => $id]);
        return $this->shape(Department::find($id)->toArray());
    }

    /** @return array<string, mixed> */
    public function update(int $id, ?string $name, ?int $sort): array
    {
        $row = Department::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'DEPARTMENT_NOT_FOUND');
        }
        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if ($name !== null) {
            $name = trim($name);
            if ($name === '' || mb_strlen($name) > 64) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'DEPARTMENT_NAME_INVALID');
            }
            $duplicateQuery = Db::name('departments')
                ->where('name', $name)
                ->where('id', '<>', $id);
            if ($row->parent_id === null) {
                $duplicateQuery->whereNull('parent_id');
            } else {
                $duplicateQuery->where('parent_id', (int) $row->parent_id);
            }
            $dup = $duplicateQuery->find();
            if ($dup) {
                throw new BusinessException(ApiResponse::CONFLICT, 'DEPARTMENT_NAME_TAKEN');
            }
            $patch['name'] = $name;
        }
        if ($sort !== null) {
            $patch['sort'] = max(0, $sort);
        }
        Department::where('id', $id)->update($patch);
        Logger::info('department.updated', ['id' => $id]);
        return $this->shape(Department::find($id)->toArray());
    }

    /** @return array<string, mixed> */
    public function setStatus(int $id, string $status): array
    {
        if (!in_array($status, ['enabled', 'disabled'], true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'DEPARTMENT_STATUS_INVALID');
        }
        $row = Department::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'DEPARTMENT_NOT_FOUND');
        }
        // No cascade — descendants stay whatever status they were. Each
        // disabled descendant independently blocks login (FR-072).
        Department::where('id', $id)->update([
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        Logger::info('department.status_changed', ['id' => $id, 'status' => $status]);
        return $this->shape(Department::find($id)->toArray());
    }

    public function delete(int $id): void
    {
        $row = Department::find($id);
        if (!$row) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'DEPARTMENT_NOT_FOUND');
        }
        $children = (int) Db::name('departments')->where('parent_id', $id)->count();
        if ($children > 0) {
            throw new BusinessException(ApiResponse::CONFLICT, 'DEPARTMENT_HAS_CHILDREN');
        }
        $staff = (int) Db::name('staff_users')->where('department_id', $id)->count();
        if ($staff > 0) {
            throw new BusinessException(ApiResponse::CONFLICT, 'DEPARTMENT_IN_USE');
        }
        $posts = (int) Db::name('posts')->where('department_id', $id)->count();
        if ($posts > 0) {
            throw new BusinessException(ApiResponse::CONFLICT, 'DEPARTMENT_HAS_POSTS');
        }
        Department::where('id', $id)->delete();
        Logger::info('department.deleted', ['id' => $id]);
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shape(array $r): array
    {
        return [
            'id'         => (int) $r['id'],
            'parent_id'  => (int) ($r['parent_id'] ?? 0),
            'name'       => (string) $r['name'],
            'path'       => (string) ($r['path'] ?? '/'),
            'depth'      => (int) $r['depth'],
            'sort'       => (int) $r['sort'],
            'status'     => (string) $r['status'],
            'created_at' => (string) ($r['created_at'] ?? ''),
            'updated_at' => (string) ($r['updated_at'] ?? ''),
        ];
    }
}
