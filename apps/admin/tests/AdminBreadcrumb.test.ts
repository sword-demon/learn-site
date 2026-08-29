// @vitest-environment happy-dom

import { describe, expect, it } from 'vitest';
import { resolveAdminBreadcrumb } from '@/composables/useAdminBreadcrumb';
import type { AdminMenuEntry } from '@/layouts/AdminMenu';

const menuEntries: AdminMenuEntry[] = [
  { path: '/', label: '工作台', permission: 'dashboard.view' },
  { path: '/courses', label: '课程管理', permission: 'course.view' },
  {
    path: '/org',
    label: '组织管理',
    permission: 'org.department',
    children: [
      { path: '/org/departments', label: '部门管理', permission: 'org.department' },
      { path: '/org/staff', label: '员工管理', permission: 'org.staff' },
    ],
  },
];

describe('resolveAdminBreadcrumb', () => {
  it('shows dashboard title on root path', () => {
    const crumbs = resolveAdminBreadcrumb('/', { title: '工作台' }, menuEntries);
    expect(crumbs).toEqual([{ title: '工作台' }]);
  });

  it('shows group and leaf for nested org route', () => {
    const crumbs = resolveAdminBreadcrumb('/org/staff', { title: '员工管理' }, menuEntries);
    expect(crumbs).toEqual([{ title: '组织管理' }, { title: '员工管理' }]);
  });

  it('adds module and page title for deep course route', () => {
    const crumbs = resolveAdminBreadcrumb(
      '/courses/12/edit',
      { title: '编辑课程' },
      menuEntries,
    );
    expect(crumbs).toEqual([
      { title: '课程管理', path: '/courses' },
      { title: '编辑课程' },
    ]);
  });

  it('respects meta.breadcrumb override', () => {
    const crumbs = resolveAdminBreadcrumb(
      '/courses/new',
      {
        title: '新建课程',
        breadcrumb: [
          { title: '课程管理', path: '/courses' },
          { title: '新建课程' },
        ],
      },
      menuEntries,
    );
    expect(crumbs).toEqual([
      { title: '课程管理', path: '/courses' },
      { title: '新建课程' },
    ]);
  });
});
