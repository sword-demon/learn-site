<?php

declare(strict_types=1);

namespace App\service;

use App\support\ApiResponse;

/**
 * Persistence-independent organization invariants shared by admin services.
 */
final class OrgPolicy
{
    public const PHONE_REGEX = '/^1[3-9]\d{9}$/';

    public static function normalizeParentId(int $parentId): ?int
    {
        return $parentId > 0 ? $parentId : null;
    }

    public static function assertStaffLogin(string $login): void
    {
        if (preg_match(self::PHONE_REGEX, trim($login)) === 1) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'INVALID_LOGIN');
        }
    }

    /** @param list<int> $postDepartmentIds */
    public static function assertStaffPlacement(
        bool $isSuperAdmin,
        ?int $departmentId,
        ?string $departmentStatus,
        array $postDepartmentIds,
    ): void {
        if (!$isSuperAdmin && ($departmentId === null || $departmentId <= 0)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_DEPARTMENT_REQUIRED');
        }
        if ($departmentId !== null && $departmentId > 0 && $departmentStatus === null) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_DEPARTMENT_INVALID');
        }
        if (!$isSuperAdmin && $departmentStatus !== 'enabled') {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_DEPARTMENT_DISABLED');
        }
        foreach ($postDepartmentIds as $postDepartmentId) {
            if ($departmentId === null || $postDepartmentId !== $departmentId) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_POST_INVALID');
            }
        }
    }

    public static function assertSelfAuthorityChange(int $actorId, int $targetId, bool $changesAuthority): void
    {
        if ($changesAuthority && $actorId === $targetId) {
            throw new BusinessException(ApiResponse::FORBIDDEN, 'SELF_GUARD');
        }
    }

    public static function assertCanDisableOrDelete(
        int $actorId,
        int $targetId,
        bool $targetIsSuperAdmin,
        int $activeSuperAdminCount,
    ): void {
        if ($actorId === $targetId) {
            throw new BusinessException(ApiResponse::FORBIDDEN, 'SELF_GUARD');
        }
        if ($targetIsSuperAdmin && $activeSuperAdminCount <= 1) {
            throw new BusinessException(ApiResponse::LAST_SUPER_ADMIN, 'LAST_SUPER_ADMIN');
        }
    }

    public static function assertCanChangeSuperAdmin(
        int $actorId,
        int $targetId,
        bool $actorIsSuperAdmin,
        bool $targetWasSuperAdmin,
        bool $targetWillBeSuperAdmin,
        int $activeSuperAdminCount,
    ): void {
        if ($targetWasSuperAdmin === $targetWillBeSuperAdmin) {
            return;
        }
        if ($actorId === $targetId) {
            throw new BusinessException(ApiResponse::FORBIDDEN, 'SELF_GUARD');
        }
        if ($targetWillBeSuperAdmin && !$actorIsSuperAdmin) {
            throw new BusinessException(ApiResponse::FORBIDDEN, 'NOT_SUPER_ADMIN');
        }
        if ($targetWasSuperAdmin && $activeSuperAdminCount <= 1) {
            throw new BusinessException(ApiResponse::LAST_SUPER_ADMIN, 'LAST_SUPER_ADMIN');
        }
    }

    /**
     * @param list<string> $actorCodes
     * @param list<string> $assignedCodes
     */
    public static function assertAssignablePermissionCodes(
        bool $actorIsSuperAdmin,
        array $actorCodes,
        array $assignedCodes,
    ): void {
        if ($actorIsSuperAdmin || in_array('*', $actorCodes, true)) {
            return;
        }
        $held = array_fill_keys($actorCodes, true);
        foreach (array_unique($assignedCodes) as $code) {
            if (!isset($held[$code])) {
                throw new BusinessException(ApiResponse::FORBIDDEN, 'PERMISSION_NOT_HELD');
            }
        }
    }
}
