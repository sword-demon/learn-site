<?php

declare(strict_types=1);

namespace App\service;

use Closure;
use support\think\Db;

/**
 * DataScopeService — US14 / T062.
 *
 * Five data scopes per FR-074 (all / dept_and_children / specified_depts
 * / dept / self). FR-076: derived rows (orders, qa, reviews, etc.)
 * follow the course's current department at read time — no denormalization.
 *
 * Department-based roles are resolved against the staff member's current
 * department. Only specified_depts reads role_scope_department rows.
 *
 * @phpstan-type ScopeShape array{all:bool,include_self:bool,department_ids:list<int>,scope:string}
 */
final class DataScopeService
{
    /**
     * @return ScopeShape
     */
    public function resolveForCourses(int $staffAccountId): array
    {
        $permissions = new PermissionService();
        if ($permissions->isSuperAdmin($staffAccountId)) {
            return self::allScope();
        }

        return self::resolveScopeRows(
            $this->roleScopeRows($staffAccountId),
            $this->staffDepartmentId($staffAccountId),
            null,
        );
    }

    /**
     * Resolve the actor's department whitelist for the given permission code.
     * Returns null when the actor has unrestricted view (scope = all);
     * callers interpret that as "no filter" so they don't have to know
     * scope internals. Returns an array of department IDs otherwise.
     *
     * Used by admin list endpoints (orders, learners, course students) so
     * the row filter is keyed by the action's permission rather than the
     * course-specific `resolveForCourses` scope.
     *
     * @return list<int>|null
     */
    public function allowedDepartmentIds(int $staffAccountId, string $permissionCode): ?array
    {
        $perm = new PermissionService();
        $codes = $perm->effectiveCodes($staffAccountId);
        if (
            $permissionCode !== ''
            && !in_array('*', $codes, true)
            && !in_array($permissionCode, $codes, true)
        ) {
            // No rights to this permission at all — empty list filters out
            // everything, surfacing as 0 rows rather than leaking scope.
            // (Authorise middleware should have caught this; this is
            // defence-in-depth for direct service-level callers.)
            return [];
        }
        $scope = $this->resolveForCourses($staffAccountId);
        if ($scope['all']) {
            return null;
        }
        return $scope['department_ids'];
    }

    /**
     * Resolve effective role rows into the FR-080 union.
     *
     * @param list<array{data_scope:mixed,department_id?:mixed,role_status?:mixed}> $rows
     * @param Closure(array<int>): array<int, array{id:int,path:string}>|null $fetchPaths
     * @return ScopeShape
     */
    public static function resolveScopeRows(array $rows, ?int $staffDepartmentId, ?Closure $fetchPaths): array
    {
        $flatDepartmentIds = [];
        $subtreeRoots = [];
        $includeSelf = false;

        foreach ($rows as $row) {
            if (($row['role_status'] ?? 'enabled') !== 'enabled') {
                continue;
            }

            $scope = (string) $row['data_scope'];
            if ($scope === PermissionService::SCOPE_ALL) {
                return self::allScope();
            }
            if ($scope === PermissionService::SCOPE_SELF) {
                $includeSelf = true;
                continue;
            }
            if ($scope === PermissionService::SCOPE_DEPT && $staffDepartmentId !== null) {
                $flatDepartmentIds[$staffDepartmentId] = true;
                continue;
            }
            if ($scope === PermissionService::SCOPE_DEPT_AND_CHILDREN && $staffDepartmentId !== null) {
                $subtreeRoots[$staffDepartmentId] = true;
                continue;
            }
            if ($scope === PermissionService::SCOPE_SPECIFIED_DEPTS) {
                $departmentId = (int) ($row['department_id'] ?? 0);
                if ($departmentId > 0) {
                    $flatDepartmentIds[$departmentId] = true;
                }
            }
        }

        if ($subtreeRoots !== []) {
            $expanded = self::expandDepartmentIds([
                'all' => false,
                'include_self' => $includeSelf,
                'department_ids' => array_keys($subtreeRoots),
                'scope' => PermissionService::SCOPE_DEPT_AND_CHILDREN,
            ], $fetchPaths);
            foreach ($expanded as $departmentId) {
                $flatDepartmentIds[$departmentId] = true;
            }
        }

        $departmentIds = array_keys($flatDepartmentIds);
        sort($departmentIds);

        return [
            'all' => false,
            'include_self' => $includeSelf,
            'department_ids' => $departmentIds,
            'scope' => $subtreeRoots !== []
                ? PermissionService::SCOPE_DEPT_AND_CHILDREN
                : ($departmentIds !== [] ? PermissionService::SCOPE_SPECIFIED_DEPTS : PermissionService::SCOPE_SELF),
        ];
    }

    /**
     * @throws BusinessException when the actor cannot move/write into $departmentId.
     */
    public function assertWritableDepartment(int $staffAccountId, int $departmentId): void
    {
        self::assertWritableDepartmentFromScope(
            $this->resolveForCourses($staffAccountId),
            $departmentId,
        );
    }

    /**
     * Pure variant for tests — caller supplies the already-resolved scope
     * so the guard can be exercised without DB. Throws on out-of-scope.
     *
     * @param ScopeShape $scope
     */
    public static function assertWritableDepartmentFromScope(array $scope, int $departmentId): void
    {
        if ($scope['all']) {
            return;
        }
        if (in_array($departmentId, $scope['department_ids'], true)) {
            return;
        }
        throw new BusinessException('FORBIDDEN', 'DEPARTMENT_OUT_OF_SCOPE');
    }

    /**
     * @param ScopeShape $scope
     * @throws BusinessException when the course itself is outside the actor's scope.
     */
    public static function assertCourseAccessibleFromScope(
        array $scope,
        int $courseDepartmentId,
        int $courseCreatorId,
        int $staffAccountId,
    ): void {
        if ($scope['all'] || in_array($courseDepartmentId, $scope['department_ids'], true)) {
            return;
        }
        if ($scope['include_self'] && $courseCreatorId === $staffAccountId) {
            return;
        }
        throw new BusinessException('FORBIDDEN', 'DEPARTMENT_OUT_OF_SCOPE');
    }

    /**
     * Pure helper — test seam takes an optional path-fetcher closure; in
     * production pass null and the helper reads the materialized path
     * straight from the departments table.
     *
     * Ponytail: the closure seam is the only way to exercise subtree
     * expansion without a fixture layer. Production path is the SQL.
     *
     * @param ScopeShape $base
     * @param Closure(array<int>): array<int, array{id:int,path:string}>|null $fetchPaths
     * @return list<int>
     */
    public static function expandDepartmentIds(array $base, ?Closure $fetchPaths): array
    {
        if ($base['all'] || $base['department_ids'] === []) {
            return [];
        }
        // FR-074: only `dept_and_children` expands. `dept` / `specified_depts`
        // are leaves; `self` carries no ids.
        if ($base['scope'] !== PermissionService::SCOPE_DEPT_AND_CHILDREN) {
            return array_values(array_unique($base['department_ids']));
        }
        $fetch = $fetchPaths ?? self::defaultPathFetcher();
        $rows = $fetch($base['department_ids']);
        $wanted = array_fill_keys($base['department_ids'], true);
        $rootPaths = [];
        $paths = [];
        foreach ($rows as $row) {
            $id = (int) $row['id'];
            $path = (string) $row['path'];
            $paths[$id] = $path;
            if (isset($wanted[$id])) {
                $rootPaths[$id] = $path;
            }
        }
        $kept = [];
        foreach ($paths as $id => $path) {
            foreach ($rootPaths as $rpath) {
                if ($path === $rpath || str_starts_with($path, $rpath . '/')) {
                    $kept[$id] = true;
                    break;
                }
            }
        }
        $list = array_keys($kept);
        sort($list);
        return $list;
    }

    /** @return list<array{data_scope:mixed,department_id:mixed,role_status:mixed}> */
    private function roleScopeRows(int $staffAccountId): array
    {
        $rows = Db::query(
            'SELECT r.data_scope, rsd.department_id, r.status AS role_status
             FROM staff_role sr
             JOIN roles r ON r.id = sr.role_id
             LEFT JOIN role_scope_department rsd ON rsd.role_id = r.id
             WHERE sr.staff_user_id = ?
             UNION ALL
             SELECT r.data_scope, rsd.department_id, r.status AS role_status
             FROM staff_post sp
             JOIN posts po ON po.id = sp.post_id AND po.status = ?
             JOIN post_role pr ON pr.post_id = sp.post_id
             JOIN roles r ON r.id = pr.role_id
             LEFT JOIN role_scope_department rsd ON rsd.role_id = r.id
             WHERE sp.staff_user_id = ?',
            [$staffAccountId, 'enabled', $staffAccountId],
        );
        return is_array($rows) ? $rows : [];
    }

    private function staffDepartmentId(int $staffAccountId): ?int
    {
        $rows = Db::query(
            'SELECT department_id FROM staff_users WHERE account_id = ? LIMIT 1',
            [$staffAccountId],
        );
        $departmentId = is_array($rows) && $rows !== [] ? ($rows[0]['department_id'] ?? null) : null;
        return $departmentId === null ? null : (int) $departmentId;
    }

    /** @return ScopeShape */
    private static function allScope(): array
    {
        return [
            'all' => true,
            'include_self' => false,
            'department_ids' => [],
            'scope' => PermissionService::SCOPE_ALL,
        ];
    }

    /** Production path-fetcher: one SELECT for roots + their path LIKE matches. */
    private static function defaultPathFetcher(): Closure
    {
        return static function (array $rootIds): array {
            if ($rootIds === []) {
                return [];
            }
            $placeholders = implode(',', array_fill(0, count($rootIds), '?'));
            $sql = "SELECT id, path FROM departments WHERE id IN ($placeholders)";
            $rows = Db::query($sql, $rootIds);
            if (!is_array($rows) || $rows === []) {
                return [];
            }
            $rootPaths = [];
            foreach ($rows as $r) {
                $rootPaths[(int) $r['id']] = (string) $r['path'];
            }
            $likeClauses = [];
            $likeArgs = [];
            foreach ($rootPaths as $rpath) {
                $likeClauses[] = 'path LIKE ?';
                $likeArgs[] = $rpath . '/%';
            }
            $sql2 = 'SELECT id, path FROM departments WHERE ('
                . implode(' OR ', $likeClauses) . ')';
            $descendants = Db::query($sql2, $likeArgs);
            return array_merge($rows, is_array($descendants) ? $descendants : []);
        };
    }
}
