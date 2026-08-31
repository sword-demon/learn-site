// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PermissionDTO, RoleDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const orgApi = vi.hoisted(() => ({
  createRole: vi.fn(),
  deleteRole: vi.fn(),
  listDepartments: vi.fn(),
  listPermissions: vi.fn(),
  listRoles: vi.fn(),
  setRoleStatus: vi.fn(),
  updateRole: vi.fn(),
}));

vi.mock('@/api/org', () => orgApi);

import RoleListView from '@/views/org/RoleListView.vue';

const permissions: PermissionDTO[] = [
  { id: 1, code: 'dashboard.view', module: 'site', description: '查看管理工作台' },
  { id: 2, code: 'category.manage', module: 'catalog', description: '管理分类' },
  { id: 3, code: 'course.view', module: 'catalog', description: '查看课程' },
  { id: 4, code: 'course.manage', module: 'catalog', description: '编辑课程内容' },
  { id: 5, code: 'course.publish', module: 'catalog', description: '发布或下架课程' },
  { id: 6, code: 'course.delete', module: 'catalog', description: '删除空白草稿' },
  { id: 7, code: 'asset.upload', module: 'catalog', description: '上传 PDF 或视频资源' },
  { id: 8, code: 'course_student.view', module: 'course_student', description: '查看课程学员名单' },
  { id: 9, code: 'course_student.reset', module: 'course_student', description: '重置学员进度' },
  {
    id: 10,
    code: 'course_student.revoke_free',
    module: 'course_student',
    description: '撤销免费课程访问权',
  },
  { id: 11, code: 'org.department', module: 'org', description: '管理部门' },
  { id: 12, code: 'org.post', module: 'org', description: '管理岗位' },
];

const role: RoleDTO = {
  id: 7,
  name: '内容编辑',
  code: 'content.editor',
  data_scope: 'all',
  status: 'enabled',
  permission_ids: [3],
  scope_department_ids: [],
  created_at: '2026-08-27 10:00:00',
  updated_at: '2026-08-27 10:00:00',
};

describe('RoleListView permission tree', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    orgApi.listRoles.mockResolvedValue({ items: [role] });
    orgApi.listPermissions.mockResolvedValue({ items: permissions });
    orgApi.listDepartments.mockResolvedValue({ items: [] });
    orgApi.updateRole.mockResolvedValue(role);
  });

  it('renders Chinese sidebar groups and selects all descendants from a parent', async () => {
    const wrapper = mount(RoleListView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    await wrapper.get('.el-table__body-wrapper button').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('课程管理');
    expect(wrapper.text()).toContain('组织管理');
    expect(wrapper.text()).toContain('部门管理');
    expect(wrapper.text()).toContain('编辑课程内容');
    expect(wrapper.text()).not.toContain('Manage categories');

    const courseToggle = wrapper.get('[data-permission-toggle="courses"]');
    expect(courseToggle.get('.el-checkbox__input').classes()).toContain('is-indeterminate');

    await courseToggle.get('input').setValue(true);
    await flushPromises();

    const selected = wrapper.findAll('[data-permission-leaf="courses"].is-checked');
    expect(selected).toHaveLength(8);
    expect(courseToggle.classes()).toContain('is-checked');
  });
});
