<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Accounts table — the canonical login-credential store for both learners
 * (phone, ^1[3-9]\d{9}$) and staff (any non-phone account string).
 *
 * (kind, login) is unique. No email field exists. No session, no email-based
 * recovery. Password hashing uses PHP password_hash() default (bcrypt).
 */
final class CreateAccounts extends AbstractMigration
{
    public function change(): void
    {
        $this->table('accounts', [
            'id'          => false,
            'primary_key' => ['id'],
        ])
        ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
        ->addColumn('kind', 'enum', ['values' => ['learner', 'staff']])
        ->addColumn('login', 'string', ['limit' => 64])
        ->addColumn('password_hash', 'string', ['limit' => 255])
        ->addColumn('must_change_password', 'boolean', ['default' => false])
        ->addColumn('status', 'enum', ['values' => ['active', 'disabled'], 'default' => 'active'])
        ->addColumn('last_login_at', 'datetime', ['null' => true])
        ->addColumn('created_at', 'datetime')
        ->addColumn('updated_at', 'datetime')
        ->addIndex(['kind', 'login'], ['unique' => true])
        ->create();
    }
}