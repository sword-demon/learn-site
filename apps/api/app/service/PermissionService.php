<?php

declare(strict_types=1);

namespace App\service;

use App\model\StaffUser;
use support\think\Db;

final class PermissionService
{
    public const SCOPE_ALL = 'all';
    public const SCOPE_DEPT_AND_CHILDREN = 'dept_and_children';
    public const SCOPE_SPECIFIED_DEPTS = 'specified_depts';
    public const SCOPE_DEPT = 'dept';
    public const SCOPE_SELF = 'self';

    /** @return string[] */
    public function effectiveCodes(int $staffAccountId): array
    {
        if ($this->isSuperAdmin($staffAccountId)) {
            return ['*'];
        }
        $deny = $this->overrideSet($staffAccountId, 'deny');
        $granted = [];
        foreach ($this->rolePermissions($staffAccountId) as $code) {
            if (!isset($deny[$code])) {
                $granted[$code] = true;
            }
        }
        foreach ($this->overrideSet($staffAccountId, 'grant') as $code => $_) {
            if (!isset($deny[$code])) {
                $granted[$code] = true;
            }
        }
        return array_keys($granted);
    }

    public function isSuperAdmin(int $staffAccountId): bool
    {
        $row = StaffUser::find($staffAccountId);
        return $row !== null && (int) $row->is_super_admin === 1;
    }

    public function isStaffActive(int $staffAccountId): bool
    {
        $rows = Db::query(
            'SELECT a.status AS account_status, s.is_super_admin, d.status AS dept_status
             FROM staff_users s
             JOIN accounts a ON a.id = s.account_id
             LEFT JOIN departments d ON d.id = s.department_id
             WHERE s.account_id = ?',
            [$staffAccountId]
        );
        $row = is_array($rows) && $rows !== [] ? $rows[0] : null;
        if (!$row) {
            return false;
        }
        if (($row['account_status'] ?? null) !== 'active') {
            return false;
        }
        if ((int) ($row['is_super_admin'] ?? 0) === 1) {
            return true;
        }
        return ($row['dept_status'] ?? null) === 'enabled';
    }

    /** @return array{all:bool,include_self:bool,department_ids:list<int>,scope:string} */
    public function effectiveScope(int $staffAccountId): array
    {
        return (new DataScopeService())->resolveForCourses($staffAccountId);
    }

    /**
     * @param list<array<string,mixed>> $rows
     * @return array{all:bool,include_self:bool,department_ids:list<int>,scope:string}
     */
    public static function unionScopeRows(array $rows): array
    {
        $all = false;
        $includeSelf = false;
        $ids = [];
        foreach ($rows as $row) {
            if (($row['role_status'] ?? 'enabled') !== 'enabled') {
                continue;
            }
            $scope = (string) ($row['data_scope'] ?? '');
            if ($scope === self::SCOPE_ALL) {
                $all = true;
            }
            if ($scope === self::SCOPE_SELF) {
                $includeSelf = true;
            }
            if (
                in_array($scope, [self::SCOPE_SPECIFIED_DEPTS, self::SCOPE_DEPT, self::SCOPE_DEPT_AND_CHILDREN], true)
                && ($row['department_id'] ?? null) !== null
            ) {
                $ids[(int) $row['department_id']] = true;
            }
        }
        if ($all) {
            return [
                'all' => true,
                'include_self' => false,
                'department_ids' => [],
                'scope' => self::SCOPE_ALL,
            ];
        }
        $departmentIds = array_keys($ids);
        return [
            'all' => false,
            'include_self' => $includeSelf,
            'department_ids' => $departmentIds,
            'scope' => $departmentIds !== [] ? self::SCOPE_SPECIFIED_DEPTS : self::SCOPE_SELF,
        ];
    }

    /** @return string[] */
    private function rolePermissions(int $staffAccountId): array
    {
        $rows = Db::query(
            'SELECT DISTINCT p.code
             FROM permissions p
             JOIN role_permission rp ON rp.permission_id = p.id
             WHERE rp.role_id IN (
                 SELECT sr.role_id FROM staff_role sr
                 JOIN roles r ON r.id = sr.role_id AND r.status = ?
                 WHERE sr.staff_user_id = ?
                 UNION
                 SELECT pr.role_id FROM staff_post sp
                 JOIN posts po ON po.id = sp.post_id AND po.status = ?
                 JOIN post_role pr ON pr.post_id = sp.post_id
                 JOIN roles r ON r.id = pr.role_id AND r.status = ?
                 WHERE sp.staff_user_id = ?
             )',
            ['enabled', $staffAccountId, 'enabled', 'enabled', $staffAccountId]
        );
        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = (string) $row['code'];
        }
        return $out;
    }

    /** @return array<string,bool> */
    private function overrideSet(int $staffAccountId, string $effect): array
    {
        $rows = Db::query(
            'SELECT p.code
             FROM staff_permission_override o
             JOIN permissions p ON p.id = o.permission_id
             WHERE o.staff_user_id = ? AND o.effect = ?',
            [$staffAccountId, $effect]
        );
        $set = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $set[(string) $row['code']] = true;
        }
        return $set;
    }
}
