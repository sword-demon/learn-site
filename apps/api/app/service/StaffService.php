<?php

declare(strict_types=1);

namespace App\service;

use App\model\Account;
use App\model\Department;
use App\model\StaffUser;
use App\support\ApiResponse;
use App\support\Logger;
use support\think\Db;

/**
 * Staff CRUD + the org guard rail from FR-072/FR-078/FR-079/FR-090:
 *
 *  - cannot delete the last super admin
 *  - cannot disable the last super admin
 *  - cannot promote oneself to super admin (self-escalation)
 *  - cannot demote the last super admin
 *  - promoting a normal staff to super admin requires the actor to already
 *    be a super admin
 *  - staff accounts MUST NOT match the 11-digit phone regex
 *  - the actor cannot grant a permission they themselves do not hold
 *    (anti-escalation on user-level overrides)
 *
 * Login-side guards (phone shape, must_change_password gate) live in
 * AuthController; this service is the single source of truth for the
 * CRUD path that writes to accounts + staff_users.
 */
final class StaffService
{
    public const PHONE_REGEX = OrgPolicy::PHONE_REGEX;

    public function __construct(
        private readonly PermissionService $perms,
        private readonly TokenService $tokens,
        private readonly PermissionOverrideService $overrides,
    ) {
    }

    /** @return list<array<string,mixed>> */
    public function listAll(?string $statusFilter, int $actorId): array
    {
        $q = Db::name('staff_users')
            ->alias('s')
            ->join('accounts a', 'a.id = s.account_id')
            ->leftJoin('departments d', 'd.id = s.department_id')
            ->field(
                's.account_id, s.is_super_admin, s.department_id, s.display_name, '
                . 's.created_at, s.updated_at, a.login, a.status AS account_status, '
                . 'a.last_login_at, a.must_change_password, d.name AS department_name, '
                . 'd.status AS department_status',
            );
        if ($statusFilter === 'active' || $statusFilter === 'disabled') {
            $q->where('a.status', $statusFilter);
        }
        $rows = $q->order('s.account_id', 'desc')->select()->toArray();
        return array_map(fn($r) => $this->shape($r), is_array($rows) ? $rows : []);
    }

    public function find(int $id): ?StaffUser
    {
        return StaffUser::find($id);
    }

    /**
     * @param list<int> $roleIds
     * @param list<int> $postIds
     * @return array<string, mixed>
     */
    public function create(
        string $login,
        string $password,
        string $displayName,
        bool $isSuperAdmin,
        ?int $departmentId,
        array $roleIds,
        array $postIds,
        int $actorId,
        bool $actorIsSuperAdmin,
    ): array {
        $login = trim($login);
        OrgPolicy::assertStaffLogin($login);
        if (strlen($login) < 3 || strlen($login) > 64) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'INVALID_LOGIN');
        }
        if (strlen($password) < 8 || strlen($password) > 72) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
        }
        $displayName = trim($displayName);
        if ($displayName === '' || mb_strlen($displayName) > 64) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_NAME_INVALID');
        }
        OrgPolicy::assertCanChangeSuperAdmin(
            $actorId,
            -1,
            $actorIsSuperAdmin,
            false,
            $isSuperAdmin,
            $this->activeSuperAdminCount(),
        );
        if (Account::where('kind', 'staff')->where('login', $login)->find()) {
            throw new BusinessException(ApiResponse::CONFLICT, 'STAFF_LOGIN_TAKEN');
        }
        $departmentId = $departmentId !== null && $departmentId > 0 ? $departmentId : null;
        $roleIds = self::uniqueIds($roleIds);
        $postIds = self::uniqueIds($postIds);
        $this->assertRolesEnabled($roleIds);
        OrgPolicy::assertStaffPlacement(
            $isSuperAdmin,
            $departmentId,
            $this->departmentStatus($departmentId),
            $this->postDepartmentIds($postIds),
        );
        $this->assertAssignmentPermissions($roleIds, $postIds, $actorId, $actorIsSuperAdmin);

        $now = date('Y-m-d H:i:s');
        $accountId = 0;
        Db::transaction(function () use (
            &$accountId,
            $login,
            $password,
            $displayName,
            $isSuperAdmin,
            $departmentId,
            $roleIds,
            $postIds,
            $now,
        ): void {
            $accountId = (int) Account::create([
                'kind'                 => 'staff',
                'login'                => $login,
                'password_hash'        => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password' => 1,
                'status'               => 'active',
                'last_login_at'        => null,
                'created_at'           => $now,
                'updated_at'           => $now,
            ])->id;
            StaffUser::create([
                'account_id'     => $accountId,
                'is_super_admin' => $isSuperAdmin ? 1 : 0,
                'department_id'  => $departmentId,
                'display_name'   => $displayName,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $this->bindRoles($accountId, $roleIds);
            $this->bindPosts($accountId, $postIds);
        });
        Logger::info('staff.created', [
            'account_id' => $accountId, 'actor_id' => $actorId, 'super' => $isSuperAdmin,
        ]);
        return $this->row($accountId);
    }

    /**
     * @param list<int>|null $roleIds
     * @param list<int>|null $postIds
     * @return array<string, mixed>
     */
    public function update(
        int $id,
        ?string $displayName,
        ?bool $isSuperAdmin,
        ?int $departmentId,
        ?array $roleIds,
        ?array $postIds,
        bool $resetPassword,
        ?string $newPassword,
        int $actorId,
        bool $actorIsSuperAdmin,
        int $actorAccountId,
    ): array {
        $staff = StaffUser::find($id);
        if (!$staff) {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
        }
        $account = Account::find($id);
        if (!$account || $account->kind !== 'staff') {
            throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
        }
        $wasSuper = (int) $staff->is_super_admin === 1;
        $becomesSuper = $isSuperAdmin ?? $wasSuper;
        $currentDepartmentId = $staff->department_id !== null ? (int) $staff->department_id : null;
        $finalDepartmentId = $departmentId === null
            ? $currentDepartmentId
            : ($departmentId > 0 ? $departmentId : null);
        $currentRoleIds = $this->roleIds($id);
        $currentPostIds = $this->postIds($id);
        $finalRoleIds = $roleIds !== null ? self::uniqueIds($roleIds) : $currentRoleIds;
        $finalPostIds = $postIds !== null ? self::uniqueIds($postIds) : $currentPostIds;
        $changesAuthority = $becomesSuper !== $wasSuper
            || $finalDepartmentId !== $currentDepartmentId
            || $finalRoleIds !== $currentRoleIds
            || $finalPostIds !== $currentPostIds;

        OrgPolicy::assertSelfAuthorityChange($actorAccountId, $id, $changesAuthority);
        OrgPolicy::assertCanChangeSuperAdmin(
            $actorAccountId,
            $id,
            $actorIsSuperAdmin,
            $wasSuper,
            $becomesSuper,
            $this->activeSuperAdminCount(),
        );
        if ($changesAuthority) {
            $this->assertRolesEnabled($finalRoleIds);
            OrgPolicy::assertStaffPlacement(
                $becomesSuper,
                $finalDepartmentId,
                $this->departmentStatus($finalDepartmentId),
                $this->postDepartmentIds($finalPostIds),
            );
            $this->assertAssignmentPermissions(
                $finalRoleIds,
                $finalPostIds,
                $actorId,
                $actorIsSuperAdmin,
            );
        }

        $patch = ['updated_at' => date('Y-m-d H:i:s')];
        if ($displayName !== null) {
            $displayName = trim($displayName);
            if ($displayName === '' || mb_strlen($displayName) > 64) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_NAME_INVALID');
            }
            $patch['display_name'] = $displayName;
        }
        if ($isSuperAdmin !== null) {
            $patch['is_super_admin'] = $isSuperAdmin ? 1 : 0;
        }
        if ($departmentId !== null) {
            $patch['department_id'] = $finalDepartmentId;
        }
        if ($resetPassword) {
            if (
                $newPassword === null
                || strlen($newPassword) < 8
                || strlen($newPassword) > 72
            ) {
                throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'PASSWORD_LENGTH');
            }
            $account->password_hash = password_hash($newPassword, PASSWORD_DEFAULT);
            $account->must_change_password = 0;
            $account->updated_at = date('Y-m-d H:i:s');
        }
        Db::transaction(function () use (
            $id,
            $account,
            $patch,
            $roleIds,
            $postIds,
            $isSuperAdmin,
            $actorAccountId,
            $actorIsSuperAdmin,
        ): void {
            if ($isSuperAdmin !== null) {
                $activeSuperAdminCount = $this->lockActiveSuperAdmins();
                $lockedStaff = Db::name('staff_users')->where('account_id', $id)->lock(true)->find();
                if (!$lockedStaff) {
                    throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
                }
                OrgPolicy::assertCanChangeSuperAdmin(
                    $actorAccountId,
                    $id,
                    $actorIsSuperAdmin,
                    (int) $lockedStaff['is_super_admin'] === 1,
                    $isSuperAdmin,
                    $activeSuperAdminCount,
                );
            }
            $account->save();
            StaffUser::where('account_id', $id)->update($patch);
            if ($roleIds !== null) {
                Db::name('staff_role')->where('staff_user_id', $id)->delete();
                $this->bindRoles($id, self::uniqueIds($roleIds));
            }
            if ($postIds !== null) {
                Db::name('staff_post')->where('staff_user_id', $id)->delete();
                $this->bindPosts($id, self::uniqueIds($postIds));
            }
        });
        Logger::info('staff.updated', ['account_id' => $id, 'actor_id' => $actorId]);
        if ($resetPassword) {
            $this->tokens->kickAll((string) $id);
        }
        return $this->row($id);
    }

    /** @return array<string, mixed> */
    public function setStatus(int $id, string $status, int $actorId, int $actorAccountId): array
    {
        if (!in_array($status, ['active', 'disabled'], true)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_STATUS_INVALID');
        }
        Db::transaction(function () use ($id, $status, $actorAccountId): void {
            $activeSuperAdminCount = $status === 'disabled' ? $this->lockActiveSuperAdmins() : 0;
            $staff = Db::name('staff_users')->where('account_id', $id)->lock(true)->find();
            if (!$staff) {
                throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
            }
            $account = Db::name('accounts')
                ->where('id', $id)
                ->where('kind', 'staff')
                ->lock(true)
                ->find();
            if (!$account) {
                throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
            }
            if ($status === 'disabled') {
                OrgPolicy::assertCanDisableOrDelete(
                    $actorAccountId,
                    $id,
                    (int) $staff['is_super_admin'] === 1,
                    $activeSuperAdminCount,
                );
            }
            Db::name('accounts')->where('id', $id)->update([
                'status' => $status,
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        });
        if ($status === 'disabled') {
            $this->tokens->kickAll((string) $id);
        }
        Logger::info('staff.status_changed', [
            'account_id' => $id, 'status' => $status, 'actor_id' => $actorId,
        ]);
        return $this->row($id);
    }

    public function delete(int $id, int $actorId, int $actorAccountId): void
    {
        Db::transaction(function () use ($id, $actorAccountId): void {
            $activeSuperAdminCount = $this->lockActiveSuperAdmins();
            $staff = Db::name('staff_users')->where('account_id', $id)->lock(true)->find();
            if (!$staff) {
                throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
            }
            OrgPolicy::assertCanDisableOrDelete(
                $actorAccountId,
                $id,
                (int) $staff['is_super_admin'] === 1,
                $activeSuperAdminCount,
            );
            $deleted = Db::name('accounts')->where('id', $id)->where('kind', 'staff')->delete();
            if ($deleted !== 1) {
                throw new BusinessException(ApiResponse::NOT_FOUND, 'STAFF_NOT_FOUND');
            }
        });
        $this->tokens->kickAll((string) $id);
        Logger::info('staff.deleted', ['account_id' => $id, 'actor_id' => $actorId]);
    }

    /**
     * User-level grant/deny overrides for FR-079/FR-083.
     *
     * Rules:
     *  - deny > grant (handled by PermissionService::effectiveCodes)
     *  - actor MUST already hold the permission being granted
     *  - super admin may set any override
     *  - an empty replacement clears all existing overrides
     */
    /**
     * @param list<array{code:string,effect:string}> $entries
     * @return list<array{effect:string,code:string,permission_id:int}>
     */
    public function setOverrides(int $targetId, array $entries, int $actorId): array
    {
        return $this->overrides->replace($targetId, $entries, $actorId);
    }

    /** @return list<array{effect:string,code:string,permission_id:int}> */
    public function overridesOf(int $staffId): array
    {
        return $this->overrides->list($staffId);
    }

    public function activeSuperAdminCount(): int
    {
        return (int) Db::name('staff_users')
            ->alias('s')
            ->join('accounts a', 'a.id = s.account_id')
            ->where('s.is_super_admin', 1)
            ->where('a.status', 'active')
            ->count();
    }

    /** @return array<string, mixed> Single-row fetch for the controller; [] when missing. */
    public function row(int $accountId): array
    {
        return $this->fetchRow($accountId);
    }

    private function lockActiveSuperAdmins(): int
    {
        $rows = Db::name('staff_users')
            ->alias('s')
            ->join('accounts a', 'a.id = s.account_id')
            ->where('s.is_super_admin', 1)
            ->where('a.status', 'active')
            ->field('s.account_id')
            ->order('s.account_id', 'asc')
            ->lock(true)
            ->select()
            ->toArray();
        return count(is_array($rows) ? $rows : []);
    }

    /** @param list<int> $roleIds */
    private function assertRolesEnabled(array $roleIds): void
    {
        $clean = [];
        foreach ($roleIds as $r) {
            $clean[(int) $r] = true;
        }
        unset($clean[0]);
        if ($clean === []) {
            return;
        }
        $count = (int) Db::name('roles')->whereIn('id', array_keys($clean))->where('status', 'enabled')->count();
        if ($count !== count($clean)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_ROLE_INVALID');
        }
    }

    private function departmentStatus(?int $departmentId): ?string
    {
        if ($departmentId === null || $departmentId <= 0) {
            return null;
        }
        $department = Department::find($departmentId);
        return $department ? (string) $department->status : null;
    }

    /**
     * @param list<int> $postIds
     * @return list<int>
     */
    private function postDepartmentIds(array $postIds): array
    {
        if ($postIds === []) {
            return [];
        }
        $rows = Db::name('posts')
            ->whereIn('id', $postIds)
            ->where('status', 'enabled')
            ->field('id, department_id')
            ->select()
            ->toArray();
        $items = is_array($rows) ? $rows : [];
        if (count($items) !== count($postIds)) {
            throw new BusinessException(ApiResponse::VALIDATION_FAILED, 'STAFF_POST_INVALID');
        }
        return array_map(static fn(array $row): int => (int) $row['department_id'], $items);
    }

    /**
     * @param list<int> $roleIds
     * @param list<int> $postIds
     */
    private function assertAssignmentPermissions(
        array $roleIds,
        array $postIds,
        int $actorId,
        bool $actorIsSuperAdmin,
    ): void {
        $effectiveRoleIds = array_fill_keys($roleIds, true);
        if ($postIds !== []) {
            $rows = Db::name('post_role')->whereIn('post_id', $postIds)->field('role_id')->select()->toArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                $effectiveRoleIds[(int) $row['role_id']] = true;
            }
        }
        $codes = [];
        if ($effectiveRoleIds !== []) {
            $rows = Db::name('role_permission')->alias('rp')
                ->join('roles r', 'r.id = rp.role_id')
                ->join('permissions p', 'p.id = rp.permission_id')
                ->whereIn('rp.role_id', array_keys($effectiveRoleIds))
                ->where('r.status', 'enabled')
                ->field('p.code')
                ->distinct(true)
                ->select()
                ->toArray();
            foreach (is_array($rows) ? $rows : [] as $row) {
                $codes[] = (string) $row['code'];
            }
        }
        OrgPolicy::assertAssignablePermissionCodes(
            $actorIsSuperAdmin,
            $this->perms->effectiveCodes($actorId),
            $codes,
        );
    }

    /** @return list<int> */
    private function roleIds(int $staffId): array
    {
        $rows = Db::name('staff_role')
            ->where('staff_user_id', $staffId)
            ->order('role_id', 'asc')
            ->select()
            ->toArray();
        return array_map(
            static fn(array $row): int => (int) $row['role_id'],
            is_array($rows) ? $rows : [],
        );
    }

    /** @return list<int> */
    private function postIds(int $staffId): array
    {
        $rows = Db::name('staff_post')
            ->where('staff_user_id', $staffId)
            ->order('post_id', 'asc')
            ->select()
            ->toArray();
        return array_map(
            static fn(array $row): int => (int) $row['post_id'],
            is_array($rows) ? $rows : [],
        );
    }

    /** @param list<int> $roleIds */
    private function bindRoles(int $accountId, array $roleIds): void
    {
        $seen = [];
        foreach ($roleIds as $rid) {
            $rid = (int) $rid;
            if ($rid <= 0 || isset($seen[$rid])) {
                continue;
            }
            $seen[$rid] = true;
            Db::name('staff_role')->insert([
                'staff_user_id' => $accountId,
                'role_id'       => $rid,
            ]);
        }
    }

    /** @param list<int> $postIds */
    private function bindPosts(int $accountId, array $postIds): void
    {
        $seen = [];
        foreach ($postIds as $pid) {
            $pid = (int) $pid;
            if ($pid <= 0 || isset($seen[$pid])) {
                continue;
            }
            $seen[$pid] = true;
            Db::name('staff_post')->insert([
                'staff_user_id' => $accountId,
                'post_id'       => $pid,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function fetchRow(int $accountId): array
    {
        $r = Db::name('staff_users')
            ->alias('s')
            ->join('accounts a', 'a.id = s.account_id')
            ->leftJoin('departments d', 'd.id = s.department_id')
            ->where('s.account_id', $accountId)
            ->field(
                's.account_id, s.is_super_admin, s.department_id, s.display_name, '
                . 's.created_at, s.updated_at, a.login, a.status AS account_status, '
                . 'a.last_login_at, a.must_change_password, d.name AS department_name, '
                . 'd.status AS department_status',
            )
            ->find();
        return $r ? $this->shape($r) : [];
    }

    /**
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private function shape(array $r): array
    {
        return [
            'account_id'           => (int) $r['account_id'],
            'login'                => (string) $r['login'],
            'display_name'         => (string) $r['display_name'],
            'is_super_admin'       => (int) $r['is_super_admin'] === 1,
            'department_id'        => isset($r['department_id']) ? (int) $r['department_id'] : null,
            'department_name'      => (string) ($r['department_name'] ?? ''),
            'department_status'    => (string) ($r['department_status'] ?? 'enabled'),
            'account_status'       => (string) $r['account_status'],
            'must_change_password' => (int) ($r['must_change_password'] ?? 0) === 1,
            'last_login_at'        => (string) ($r['last_login_at'] ?? ''),
            'created_at'           => (string) ($r['created_at'] ?? ''),
            'updated_at'           => (string) ($r['updated_at'] ?? ''),
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
        $ids = array_keys($out);
        sort($ids);
        return $ids;
    }
}
