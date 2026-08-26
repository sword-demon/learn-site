<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * RBAC schema — departments (5 levels, materialized path), posts (bound to a
 * department), roles (with data_scope), permissions (seeded, immutable code),
 * and the join tables that compose effective authority.
 *
 * Per FR-080: data_scope = all | dept_and_children | specified_depts | dept | self.
 * self = created_by_staff_id, NOT last editor.
 * Per FR-075/079: user-level grant/deny with deny-wins precedence.
 */
final class CreateRbac extends AbstractMigration
{
    public function change(): void
    {
        // Departments
        $this->table('departments', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('parent_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('path', 'string', ['limit' => 255])
            ->addColumn('depth', 'integer', ['default' => 1])
            ->addColumn('sort', 'integer', ['default' => 0])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['parent_id'])
            ->addIndex(['path'])
            ->create();

        // Learners (one row per learner account; carrier for public profile)
        $this->table('learners', ['id' => false, 'primary_key' => ['account_id']])
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('nickname', 'string', ['limit' => 32, 'null' => true])
            ->addColumn('avatar_url', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('show_on_course', 'boolean', ['default' => false])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('account_id', 'accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // Staff (super_admin flag, department binding, display name)
        $this->table('staff_users', ['id' => false, 'primary_key' => ['account_id']])
            ->addColumn('account_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('is_super_admin', 'boolean', ['default' => false])
            ->addColumn('department_id', 'biginteger', ['signed' => false, 'null' => true])
            ->addColumn('display_name', 'string', ['limit' => 64])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('account_id', 'accounts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('department_id', 'departments', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        // Posts
        $this->table('posts', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('department_id', 'biginteger', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addForeignKey('department_id', 'departments', 'id', ['delete' => 'RESTRICT', 'update' => 'CASCADE'])
            ->create();

        // Roles
        $this->table('roles', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('name', 'string', ['limit' => 64])
            ->addColumn('code', 'string', ['limit' => 64])
            ->addColumn('data_scope', 'enum', [
                'values' => ['all', 'dept_and_children', 'specified_depts', 'dept', 'self'],
                'default' => 'self',
            ])
            ->addColumn('status', 'enum', ['values' => ['enabled', 'disabled'], 'default' => 'enabled'])
            ->addColumn('created_at', 'datetime')
            ->addColumn('updated_at', 'datetime')
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // Permissions (seeded, never created via UI)
        $this->table('permissions', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('code', 'string', ['limit' => 64])
            ->addColumn('module', 'string', ['limit' => 32])
            ->addColumn('description', 'string', ['limit' => 255, 'null' => true])
            ->addIndex(['code'], ['unique' => true])
            ->create();

        // Join tables
        $this->table('staff_post', ['id' => false, 'primary_key' => ['staff_user_id', 'post_id']])
            ->addColumn('staff_user_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('post_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addForeignKey('staff_user_id', 'staff_users', 'account_id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('staff_role', ['id' => false, 'primary_key' => ['staff_user_id', 'role_id']])
            ->addColumn('staff_user_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('role_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addForeignKey('staff_user_id', 'staff_users', 'account_id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('post_role', ['id' => false, 'primary_key' => ['post_id', 'role_id']])
            ->addColumn('post_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('role_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addForeignKey('post_id', 'posts', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        $this->table('role_permission', ['id' => false, 'primary_key' => ['role_id', 'permission_id']])
            ->addColumn('role_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('permission_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permissions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // specified_depts materialization (FR-080: does NOT include children)
        $this->table('role_scope_department', ['id' => false, 'primary_key' => ['role_id', 'department_id']])
            ->addColumn('role_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addColumn('department_id', 'biginteger', ['signed' => false, 'null' => false])
            ->addForeignKey('role_id', 'roles', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('department_id', 'departments', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->create();

        // User-level grant/deny (FR-079, FR-083; deny wins)
        $this->table('staff_permission_override', ['id' => false, 'primary_key' => ['id']])
            ->addColumn('id', 'biginteger', ['identity' => true, 'signed' => false])
            ->addColumn('staff_user_id', 'biginteger', ['signed' => false])
            ->addColumn('permission_id', 'biginteger', ['signed' => false])
            ->addColumn('effect', 'enum', ['values' => ['grant', 'deny']])
            ->addColumn('actor_account_id', 'biginteger', ['signed' => false])
            ->addColumn('reason', 'string', ['limit' => 255, 'null' => true])
            ->addColumn('created_at', 'datetime')
            ->addForeignKey('staff_user_id', 'staff_users', 'account_id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addForeignKey('permission_id', 'permissions', 'id', ['delete' => 'CASCADE', 'update' => 'CASCADE'])
            ->addIndex(['staff_user_id', 'permission_id'], ['unique' => true])
            ->create();
    }
}
