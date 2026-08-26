<?php

declare(strict_types=1);

namespace App\service;

use App\model\StaffUser;
use App\support\ApiResponse;
use App\support\Logger;
use support\think\Db;

/**
 * Manages user-level grant/deny overrides for a staff account.
 *
 * The service owns validation and replacement of the override set. Effective
 * permission calculation remains in PermissionService so every protected
 * request applies the same deny-first rule.
 */
final class PermissionOverrideService
{
    public function __construct(
        private readonly PermissionService $permissions,
    ) {
    }

    /**
     * Replace all overrides for one staff account atomically.
     *
     * @param list<array{code:string,effect:string}> $entries
     * @return list<array{effect:string,code:string,permission_id:int}>
     */
    public function replace(int $targetId, array $entries, int $actorId): array
    {
        if (StaffUser::find($targetId) === null) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
        }
        OrgPolicy::assertSelfAuthorityChange($actorId, $targetId, true);

        $actorCodes = $this->permissions->effectiveCodes($actorId);
        $isWildcard = in_array('*', $actorCodes, true);
        $clean = [];
        $permissionIds = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_ENTRY_INVALID');
            }

            $code = trim((string) ($entry['code'] ?? ''));
            $effect = (string) ($entry['effect'] ?? '');
            if ($code === '') {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_CODE_UNKNOWN');
            }
            if (!in_array($effect, ['grant', 'deny'], true)) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_EFFECT_INVALID');
            }
            $permissionId = Db::name('permissions')->where('code', $code)->value('id');
            if ($permissionId === null) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'OVERRIDE_CODE_UNKNOWN');
            }
            if (!$isWildcard && !in_array($code, $actorCodes, true)) {
                throw new BusinessException(ApiResponse::FORBIDDEN, 'OVERRIDE_NOT_HELD');
            }
            $clean[$code] = $effect;
            $permissionIds[$code] = (int) $permissionId;
        }

        $now = date('Y-m-d H:i:s');
        Db::transaction(function () use ($targetId, $actorId, $clean, $permissionIds, $now): void {
            Db::name('staff_permission_override')->where('staff_user_id', $targetId)->delete();
            foreach ($clean as $code => $effect) {
                Db::name('staff_permission_override')->insert([
                    'staff_user_id' => $targetId,
                    'permission_id' => $permissionIds[$code],
                    'effect' => $effect,
                    'actor_account_id' => $actorId,
                    'reason' => null,
                    'created_at' => $now,
                ]);
            }
        });

        Logger::info('staff.overrides_set', [
            'target_id' => $targetId,
            'actor_id' => $actorId,
            'count' => count($clean),
        ]);
        return $this->list($targetId);
    }

    /** @return list<array{effect:string,code:string,permission_id:int}> */
    public function list(int $staffId): array
    {
        $rows = Db::name('staff_permission_override')
            ->alias('o')
            ->join('permissions p', 'p.id = o.permission_id')
            ->where('o.staff_user_id', $staffId)
            ->field('o.effect, p.code, p.id AS permission_id')
            ->order('o.id', 'asc')
            ->select()
            ->toArray();

        $out = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $out[] = [
                'effect' => (string) $row['effect'],
                'code' => (string) $row['code'],
                'permission_id' => (int) $row['permission_id'],
            ];
        }
        return $out;
    }
}
