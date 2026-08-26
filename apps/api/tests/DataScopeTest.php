<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\DataScopeService;
use App\service\PermissionService;
use PHPUnit\Framework\TestCase;

/**
 * DataScopeTest — US14 / T062-T064.
 *
 *  - 五种数据范围 (all / dept_and_children / specified_depts / dept / self)
 *  - 指定部门不含下级 (FR-074)
 *  - 派生数据跟随课程当前部门 (FR-076)
 *  - 父/子/旁支三门课, 范围结果 100% 一致 (independent test, #12)
 *
 * The repo has no DB fixture layer, so we exercise the pure helpers
 * directly. The pure helpers take an optional path-fetch closure so the
 * subtree expansion is testable without a live MySQL connection.
 */
final class DataScopeTest extends TestCase
{
    public function testResolveDeptUsesStaffDepartmentInsteadOfSpecifiedScopeRows(): void
    {
        $resolved = DataScopeService::resolveScopeRows(
            [['data_scope' => PermissionService::SCOPE_DEPT, 'department_id' => null]],
            7,
            $this->noSubtree(),
        );

        $this->assertFalse($resolved['all']);
        $this->assertFalse($resolved['include_self']);
        $this->assertSame([7], $resolved['department_ids']);
        $this->assertSame(PermissionService::SCOPE_SPECIFIED_DEPTS, $resolved['scope']);
    }

    public function testResolveMixedScopesUnionsOwnSubtreeSpecifiedBranchAndSelf(): void
    {
        $paths = [
            10 => '/1/10',
            11 => '/1/10/11',
            20 => '/1/20',
            21 => '/1/20/21',
        ];
        $fetchPaths = static function (array $rootIds) use ($paths): array {
            $rows = [];
            foreach ($paths as $id => $path) {
                foreach ($rootIds as $rootId) {
                    $rootPath = $paths[$rootId] ?? '';
                    if ($path === $rootPath || str_starts_with($path, $rootPath . '/')) {
                        $rows[] = ['id' => $id, 'path' => $path];
                        break;
                    }
                }
            }
            return $rows;
        };

        $resolved = DataScopeService::resolveScopeRows([
            ['data_scope' => PermissionService::SCOPE_DEPT_AND_CHILDREN, 'department_id' => null],
            ['data_scope' => PermissionService::SCOPE_SPECIFIED_DEPTS, 'department_id' => 20],
            ['data_scope' => PermissionService::SCOPE_SELF, 'department_id' => null],
        ], 10, $fetchPaths);

        $this->assertFalse($resolved['all']);
        $this->assertTrue($resolved['include_self']);
        $this->assertSame([10, 11, 20], $resolved['department_ids']);
        $this->assertNotContains(21, $resolved['department_ids'], 'specified scope must not include children');
        $this->assertSame(PermissionService::SCOPE_DEPT_AND_CHILDREN, $resolved['scope']);
    }

    public function testResolveAllWinsAndDisabledRolesDoNotWidenScope(): void
    {
        $resolved = DataScopeService::resolveScopeRows([
            ['data_scope' => PermissionService::SCOPE_ALL, 'department_id' => null],
            [
                'data_scope' => PermissionService::SCOPE_SPECIFIED_DEPTS,
                'department_id' => 99,
                'role_status' => 'disabled',
            ],
        ], 7, $this->noSubtree());

        $this->assertSame([
            'all' => true,
            'include_self' => false,
            'department_ids' => [],
            'scope' => PermissionService::SCOPE_ALL,
        ], $resolved);
    }

    // ─── expandDepartmentIds (pure helper, FR-074 + FR-076) ────────────

    public function testExpandKeepsSpecifiedDeptsFlat(): void
    {
        // FR-074: 指定部门 only contains the picked leaves — never children.
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => false, 'include_self' => false, 'department_ids' => [10, 20], 'scope' => 'specified_depts'],
            $this->noSubtree(),
        );
        $this->assertSame([10, 20], $expanded);
    }

    public function testExpandDoesNotExpandDeptScope(): void
    {
        // FR-074: dept is a single root — same flat rule as specified_depts.
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => false, 'include_self' => false, 'department_ids' => [7], 'scope' => 'dept'],
            $this->subtreeReturning([7], [7, 17]),
        );
        $this->assertSame([7], $expanded, 'dept must NOT include children');
    }

    public function testExpandExpandsDeptAndChildren(): void
    {
        // dept_and_children IS expected to include the subtree.
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => false, 'include_self' => false, 'department_ids' => [1], 'scope' => 'dept_and_children'],
            $this->subtreeReturning([1], [1, 4, 12, 17]),
        );
        sort($expanded);
        $this->assertSame([1, 4, 12, 17], $expanded);
    }

    public function testAllScopeShortCircuitsToEmptyList(): void
    {
        // all=true means "no department filter" — the resolver returns []
        // and the caller skips the WHERE clause entirely.
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => true, 'include_self' => false, 'department_ids' => [], 'scope' => 'all'],
            $this->noSubtree(),
        );
        $this->assertSame([], $expanded);
    }

    public function testExpandDedupesAcrossMixedScopes(): void
    {
        // Two roots tagged dept_and_children overlap on one descendant;
        // result must be deduped + sorted.
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => false, 'include_self' => false, 'department_ids' => [1, 2], 'scope' => 'dept_and_children'],
            $this->subtreeReturning([1, 2], [1, 2, 4, 5, 9]),
        );
        sort($expanded);
        $this->assertSame([1, 2, 4, 5, 9], $expanded);
    }

    // ─── PermissionService::unionScopeRows (FR-080) ───────────────────

    public function testUnionScopeRowsKeepsDeptAndSelf(): void
    {
        // FR-080: dept + self OR together — include_self is true and the
        // dept id is kept.
        $merged = PermissionService::unionScopeRows([
            ['data_scope' => 'dept', 'department_id' => 5, 'role_status' => 'enabled'],
            ['data_scope' => 'self', 'department_id' => null, 'role_status' => 'enabled'],
        ]);
        $this->assertFalse($merged['all']);
        $this->assertTrue($merged['include_self']);
        $this->assertSame([5], $merged['department_ids']);
    }

    public function testUnionScopeRowsAllWinsOverEverythingElse(): void
    {
        // FR-080: any role with data_scope='all' produces all=true, the
        // other rows are irrelevant.
        $merged = PermissionService::unionScopeRows([
            ['data_scope' => 'dept', 'department_id' => 5, 'role_status' => 'enabled'],
            ['data_scope' => 'all', 'department_id' => null, 'role_status' => 'enabled'],
        ]);
        $this->assertTrue($merged['all']);
        $this->assertFalse($merged['include_self']);
        $this->assertSame([], $merged['department_ids']);
    }

    // ─── assertWritableDepartment (T064, FR-076) ──────────────────────

    public function testAssertWritableAllowsWhenAll(): void
    {
        // Super admin / role.all may write to any department.
        DataScopeService::assertWritableDepartmentFromScope(
            ['all' => true, 'include_self' => false, 'department_ids' => [], 'scope' => 'all'],
            999,
        );
        $this->assertTrue(true, 'no exception for all=true');
    }

    public function testAssertWritableAllowsWhenInScope(): void
    {
        // The target department is one of the actor's grant rows.
        DataScopeService::assertWritableDepartmentFromScope(
            ['all' => false, 'include_self' => false, 'department_ids' => [5, 7, 9], 'scope' => 'specified_depts'],
            7,
        );
        $this->assertTrue(true, 'no exception for in-scope target');
    }

    public function testAssertWritableRejectsSelfOnly(): void
    {
        // include_self + no department_ids means "only my own creations".
        // A move to an unrelated department must fail.
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('DEPARTMENT_OUT_OF_SCOPE');
        DataScopeService::assertWritableDepartmentFromScope(
            ['all' => false, 'include_self' => true, 'department_ids' => [], 'scope' => 'self'],
            5,
        );
    }

    public function testAssertWritableRejectsOutOfScope(): void
    {
        $this->expectException(BusinessException::class);
        $this->expectExceptionMessage('DEPARTMENT_OUT_OF_SCOPE');
        DataScopeService::assertWritableDepartmentFromScope(
            ['all' => false, 'include_self' => false, 'department_ids' => [5, 7], 'scope' => 'specified_depts'],
            12,
        );
    }

    // ─── Source-grep pins (T063 / T064 / FR-076 invariant) ────────────

    public function testCourseServiceListForAdminUsesResolver(): void
    {
        // T063: listForAdmin must call DataScopeService::resolveForCourses
        // and apply the resulting WHERE clause. If a future refactor bypasses
        // the resolver the test catches it.
        $src = (string) file_get_contents(dirname(__DIR__) . '/app/service/CourseService.php');
        $this->assertStringContainsString(
            'use App\\service\\DataScopeService',
            $src,
            'CourseService must require DataScopeService'
        );
        $this->assertStringContainsString(
            'resolveForCourses',
            $src,
            'listForAdmin must call resolveForCourses()'
        );
        $this->assertStringContainsString(
            "'department_id', 'in'",
            $src,
            'listForAdmin must filter by department_id IN (...)'
        );
        $this->assertStringContainsString(
            'created_by_staff_id',
            $src,
            'listForAdmin must still gate include_self via created_by_staff_id'
        );
        $this->assertStringContainsString(
            "'status'",
            $src,
            'status filter must remain'
        );
        $this->assertStringContainsString(
            "'category_id'",
            $src,
            'category_id filter must remain'
        );
        $this->assertStringContainsString(
            "'q'",
            $src,
            'q filter must remain'
        );
    }

    public function testCourseServiceUpdateCourseGuardsTargetDepartment(): void
    {
        // T064 / FR-076: when the caller patches department_id, the target
        // department must be in the actor's scope. The pin is by source.
        $src = (string) file_get_contents(dirname(__DIR__) . '/app/service/CourseService.php');
        $this->assertStringContainsString(
            'assertWritableDepartment',
            $src,
            'updateCourse must call assertWritableDepartment on department_id patch'
        );
        $this->assertStringContainsString(
            "array_key_exists('department_id'",
            $src,
            'scope guard must only fire when the patch actually includes department_id'
        );
    }

    public function testDerivedTablesDoNotDenormalizeDepartmentId(): void
    {
        // FR-076: orders / enrollments / entitlements hold course_id only.
        // Adding a denormalized department_id to these tables would silently
        // break 派生数据跟随 (the spec's whole point).
        $migrationsDir = dirname(__DIR__) . '/database/migrations';
        $derived = ['orders', 'course_entitlements', 'course_enrollments'];
        foreach ($derived as $table) {
            $found = false;
            foreach ((array) glob($migrationsDir . '/*.php') as $file) {
                $src = (string) file_get_contents((string) $file);
                if (!preg_match("/['\"]" . preg_quote($table, '/') . "['\"]/", $src)) {
                    continue;
                }
                $found = true;
                if (
                    preg_match(
                        "/['\"]" . preg_quote($table, '/')
                            . "['\"][\\s\\S]{0,2000}?addColumn\\(\\s*['\"]department_id['\"]/",
                        $src,
                    )
                ) {
                    $this->fail("$table must NOT carry a denormalized department_id column (FR-076)");
                }
            }
            $this->assertTrue($found, "Migration for $table should exist");
        }
    }

    // ─── Independent test: 父/子/旁支三门课 ──────────────────────────

    public function testParentChildSiblingThreeCoursesShape(): void
    {
        // Independent test for US14: given parent=10, child=11 (descendant),
        // sibling=12 (NOT a descendant of 10), a dept_and_children scope
        // rooted at 10 must include 10+11 and MUST exclude 12.
        $depts = [
            10 => '/1/10',
            11 => '/1/10/11', // child of 10
            12 => '/1/9/12',  // sibling branch — NOT under 10
        ];
        $fetchPaths = function (array $rootIds) use ($depts): array {
            // Materialized-path LIKE expansion for dept_and_children.
            $out = [];
            foreach ($rootIds as $rid) {
                $rpath = $depts[$rid] ?? '/';
                $out[] = ['id' => $rid, 'path' => $rpath];
                foreach ($depts as $did => $dpath) {
                    if ($did === $rid) {
                        continue;
                    }
                    if (str_starts_with($dpath, $rpath . '/')) {
                        $out[] = ['id' => $did, 'path' => $dpath];
                    }
                }
            }
            return $out;
        };
        $expanded = DataScopeService::expandDepartmentIds(
            ['all' => false, 'include_self' => false, 'department_ids' => [10], 'scope' => 'dept_and_children'],
            $fetchPaths,
        );
        sort($expanded);
        $this->assertSame([10, 11], $expanded, 'sibling=12 must be excluded');
    }

    // ─── Test helpers ─────────────────────────────────────────────────

    /** A path-fetcher that returns only the root ids (no children). */
    private function noSubtree(): \Closure
    {
        return function (array $rootIds): array {
            $out = [];
            foreach ($rootIds as $r) {
                $out[] = ['id' => $r, 'path' => '/' . $r];
            }
            return $out;
        };
    }

    /**
     * A path-fetcher that returns each root (path /<root>) plus each
     * provided subtree id as a real descendant path (/<root>/<sub>). This
     * matches what a real Materialized-Path LIKE expansion would return.
     */
    private function subtreeReturning(array $rootIds, array $subtreeIds): \Closure
    {
        return function (array $roots) use ($rootIds, $subtreeIds): array {
            $out = [];
            foreach ($rootIds as $r) {
                $out[] = ['id' => $r, 'path' => '/' . $r];
            }
            foreach ($subtreeIds as $id) {
                if (in_array($id, $rootIds, true)) {
                    continue;
                }
                // Pick the root whose id is the prefix of this subtree id, or
                // fall back to the first root.
                $parent = $rootIds[0];
                foreach ($rootIds as $r) {
                    if ((string) $id !== '' && str_starts_with((string) $id, (string) $r)) {
                        $parent = $r;
                        break;
                    }
                }
                $out[] = ['id' => $id, 'path' => '/' . $parent . '/' . $id];
            }
            return $out;
        };
    }
}
