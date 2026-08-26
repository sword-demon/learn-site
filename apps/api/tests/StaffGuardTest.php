<?php

declare(strict_types=1);

namespace Tests;

use App\service\BusinessException;
use App\service\OrgPolicy;
use PHPUnit\Framework\TestCase;

final class StaffGuardTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        self::assertTrue(
            class_exists(OrgPolicy::class),
            'Phase 8 requires a real, persistence-independent org policy',
        );
    }

    public function testRootDepartmentUsesNullForeignKey(): void
    {
        self::assertNull(OrgPolicy::normalizeParentId(0));
        self::assertSame(12, OrgPolicy::normalizeParentId(12));
    }

    public function testPhoneShapedStaffLoginIsRejected(): void
    {
        $this->assertPolicyError('INVALID_LOGIN', static function (): void {
            OrgPolicy::assertStaffLogin('13912345678');
        });

        OrgPolicy::assertStaffLogin('ops.team');
        $this->addToAssertionCount(1);
    }

    public function testOrdinaryStaffRequiresAnEnabledDepartment(): void
    {
        $this->assertPolicyError('STAFF_DEPARTMENT_REQUIRED', static function (): void {
            OrgPolicy::assertStaffPlacement(false, null, null, []);
        });
        $this->assertPolicyError('STAFF_DEPARTMENT_DISABLED', static function (): void {
            OrgPolicy::assertStaffPlacement(false, 7, 'disabled', []);
        });

        OrgPolicy::assertStaffPlacement(false, 7, 'enabled', []);
        OrgPolicy::assertStaffPlacement(true, null, null, []);
        $this->addToAssertionCount(2);
    }

    public function testEveryPostMustBelongToTheStaffDepartment(): void
    {
        OrgPolicy::assertStaffPlacement(false, 7, 'enabled', [7, 7]);
        $this->addToAssertionCount(1);

        $this->assertPolicyError('STAFF_POST_INVALID', static function (): void {
            OrgPolicy::assertStaffPlacement(false, 7, 'enabled', [7, 8]);
        });
    }

    public function testStaffCannotChangeTheirOwnAuthorityAssignments(): void
    {
        $this->assertPolicyError('SELF_GUARD', static function (): void {
            OrgPolicy::assertSelfAuthorityChange(22, 22, true);
        });

        OrgPolicy::assertSelfAuthorityChange(22, 22, false);
        OrgPolicy::assertSelfAuthorityChange(22, 23, true);
        $this->addToAssertionCount(2);
    }

    public function testStaffCannotDisableOrDeleteThemselves(): void
    {
        $this->assertPolicyError('SELF_GUARD', static function (): void {
            OrgPolicy::assertCanDisableOrDelete(31, 31, false, 1);
        });
    }

    public function testLastActiveSuperAdminCannotBeRemoved(): void
    {
        $this->assertPolicyError('LAST_SUPER_ADMIN', static function (): void {
            OrgPolicy::assertCanDisableOrDelete(40, 41, true, 1);
        });

        OrgPolicy::assertCanDisableOrDelete(40, 41, true, 2);
        $this->addToAssertionCount(1);
    }

    public function testOnlySuperAdminCanPromoteStaff(): void
    {
        $this->assertPolicyError('NOT_SUPER_ADMIN', static function (): void {
            OrgPolicy::assertCanChangeSuperAdmin(50, 51, false, false, true, 1);
        });

        OrgPolicy::assertCanChangeSuperAdmin(50, 51, true, false, true, 1);
        $this->addToAssertionCount(1);
    }

    public function testLastSuperAdminCannotBeDemotedAndSelfChangeIsRejected(): void
    {
        $this->assertPolicyError('LAST_SUPER_ADMIN', static function (): void {
            OrgPolicy::assertCanChangeSuperAdmin(60, 61, true, true, false, 1);
        });
        $this->assertPolicyError('SELF_GUARD', static function (): void {
            OrgPolicy::assertCanChangeSuperAdmin(61, 61, true, true, false, 2);
        });
    }

    public function testActorCannotAssignPermissionsTheyDoNotHold(): void
    {
        OrgPolicy::assertAssignablePermissionCodes(false, ['org.staff', 'qa.view'], ['qa.view']);
        $this->addToAssertionCount(1);

        $this->assertPolicyError('PERMISSION_NOT_HELD', static function (): void {
            OrgPolicy::assertAssignablePermissionCodes(
                false,
                ['org.staff', 'qa.view'],
                ['qa.view', 'order.view'],
            );
        });

        OrgPolicy::assertAssignablePermissionCodes(true, [], ['order.view']);
        $this->addToAssertionCount(1);
    }

    /** @param callable():void $operation */
    private function assertPolicyError(string $message, callable $operation): void
    {
        try {
            $operation();
            self::fail("Expected policy error {$message}");
        } catch (BusinessException $e) {
            self::assertSame($message, $e->getMessage());
        }
    }
}
