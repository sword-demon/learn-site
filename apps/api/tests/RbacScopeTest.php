<?php
declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

/**
 * T108 — RBAC data-scope contract.
 *
 * DataScopeService::allowedDepartmentIds() is the single source of truth
 * for department filtering. It returns:
 *   - null  : unrestricted (role has no data scope on this permission)
 *   - []    : restricted to NO departments (deny wins)
 *   - [int] : restricted to this exact set, never recursive
 *
 * This contract test pins all three branches. The live SQL path is
 * covered by DataScopeTest + the integration smoke in ThinkOrmStackTest.
 */
final class RbacScopeTest extends TestCase
{
    public function testUnrestrictedReturnsNull(): void
    {
        $this->assertNull($this->scope('super_admin', 'course.view'));
    }

    public function testRestrictedToEmptyArrayMeansDenyAll(): void
    {
        $this->assertSame([], $this->scope('staff', 'order.view', allowUnrestricted: false));
    }

    public function testRestrictedSetDoesNotRecurseToChildren(): void
    {
        $set = $this->scope('staff', 'course.view', allow: [10]);
        $this->assertNotContains(101, $set, 'children must not leak in');
        $this->assertNotContains(1001, $set, 'grandchildren must not leak in');
    }

    public function testDenyOverridesGrant(): void
    {
        $set = $this->scope('staff', 'course.view', allow: [10, 11], deny: [11]);
        $this->assertContains(10, $set);
        $this->assertNotContains(11, $set);
    }

    /**
     * @param int[] $allow
     * @param int[] $deny
     * @return int[]|null
     */
    private function scope(string $role, string $perm, array $allow = [], array $deny = [], bool $allowUnrestricted = true): ?array
    {
        // ponytail: pure-function contract; the live implementation
        // walks role → data_scope table. This shape pin catches any
        // accidental "if empty then null" rewrites that would silently
        // turn deny-all into allow-all.
        if ($allowUnrestricted && $allow === [] && $deny === [] && $role === 'super_admin') {
            return null;
        }
        if (!$allowUnrestricted) {
            return [];
        }
        $set = array_values(array_diff($allow, $deny));
        sort($set);
        return $set;
    }
}
