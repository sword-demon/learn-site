<?php

declare(strict_types=1);

use Phinx\Seed\AbstractSeed;

/**
 * SuperAdminSeeder — creates the very first super-admin from env vars.
 *
 * Idempotent: re-running the seed will not duplicate the account, role, or
 * role_permission grants. The account is `must_change_password = true` per
 * FR-090; the staff user is unlocked from any department.
 */
final class SuperAdminSeeder extends AbstractSeed
{
    public function run(): void
    {
        $account = (string) (getenv('SUPER_ADMIN_ACCOUNT') ?: '');
        $password = (string) (getenv('SUPER_ADMIN_PASSWORD') ?: '');
        if ($account === '' || $password === '') {
            echo "[seed] SUPER_ADMIN_ACCOUNT/SUPER_ADMIN_PASSWORD not set — skipping\n";
            return;
        }
        if (preg_match('/^1[3-9]\d{9}$/', $account)) {
            // Per FR-003 + spec, staff accounts must NOT match a phone.
            echo "[seed] SUPER_ADMIN_ACCOUNT looks like a phone — refusing\n";
            return;
        }
        $pdo = $this->getAdapter()->getConnection();
        $stmt = $pdo->prepare('SELECT id FROM accounts WHERE kind = ? AND login = ?');
        $stmt->execute(['staff', $account]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if ($row === false) {
            $this->table('accounts')->insert([
                'kind'                  => 'staff',
                'login'                 => $account,
                'password_hash'         => password_hash($password, PASSWORD_DEFAULT),
                'must_change_password'  => 1,
                'status'                => 'active',
                'created_at'            => date('Y-m-d H:i:s'),
                'updated_at'            => date('Y-m-d H:i:s'),
            ])->saveData();
            $stmt->execute(['staff', $account]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        }
        $accountId = (int) ($row['id'] ?? 0);

        $stmt = $pdo->prepare('SELECT account_id FROM staff_users WHERE account_id = ?');
        $stmt->execute([$accountId]);
        if ($stmt->fetch(\PDO::FETCH_ASSOC) === false) {
            $this->table('staff_users')->insert([
                'account_id'      => $accountId,
                'is_super_admin'  => 1,
                'department_id'   => null,
                'display_name'    => 'Super Admin',
                'created_at'      => date('Y-m-d H:i:s'),
                'updated_at'      => date('Y-m-d H:i:s'),
            ])->saveData();
        }
    }
}
