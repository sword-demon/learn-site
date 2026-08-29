// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { PermissionDTO, StaffDetailDTO } from '@learn-site/contracts';
import { installElementPlus } from '@/plugins/element-plus';

const orgApi = vi.hoisted(() => ({
  getStaff: vi.fn(),
  listPermissions: vi.fn(),
  setStaffOverrides: vi.fn(),
}));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));
const routerApi = vi.hoisted(() => ({
  push: vi.fn(),
  route: undefined as { params: { id: string } } | undefined,
}));

vi.mock('@/api/org', () => orgApi);
vi.mock('@/api/http', () => authApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}));

import StaffOverrideView from '@/views/org/StaffOverrideView.vue';

const permissions: PermissionDTO[] = [
  { id: 1, code: 'order.view', module: '订单', description: '查看订单' },
  { id: 2, code: 'qa.answer', module: '问答', description: '回答问题' },
  { id: 3, code: 'review.moderate', module: '评价', description: '审核评价' },
];

function staff(overrides: StaffDetailDTO['overrides'] = []): StaffDetailDTO {
  return {
    staff: {
      account_id: 12,
      login: 'editor',
      display_name: '内容编辑',
      is_super_admin: false,
      department_id: 4,
      department_name: '内容部',
      department_status: 'enabled',
      account_status: 'active',
      must_change_password: false,
      last_login_at: '2026-08-26 10:00:00',
      created_at: '2026-08-25 10:00:00',
      updated_at: '2026-08-26 10:00:00',
    },
    roles: [7],
    posts: [3],
    overrides,
  };
}

describe('StaffOverrideView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route = reactive({ params: { id: '12' } });
    authApi.hasPermission.mockReturnValue(true);
    orgApi.getStaff.mockResolvedValue(
      staff([{ effect: 'deny', code: 'order.view', permission_id: 1 }]),
    );
    orgApi.listPermissions.mockResolvedValue({ items: permissions });
    orgApi.setStaffOverrides.mockResolvedValue({
      overrides: [{ effect: 'grant', code: 'qa.answer', permission_id: 2 }],
    });
  });

  it('loads existing overrides and sends the complete replacement set', async () => {
    const wrapper = mount(StaffOverrideView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('内容编辑');
    expect(wrapper.get('button.deny.active').text()).toContain('禁止');

    const qaRow = wrapper.findAll('tbody tr').find((row) => row.text().includes('qa.answer'));
    expect(qaRow).toBeDefined();
    await qaRow?.get('button[title="授予或清除覆盖"]').trigger('click');
    await wrapper.get('button.btn-primary').trigger('click');
    await flushPromises();

    expect(orgApi.setStaffOverrides).toHaveBeenCalledWith(12, {
      entries: [
        { code: 'order.view', effect: 'deny' },
        { code: 'qa.answer', effect: 'grant' },
      ],
    });
  });

  it('disables permissions the operator does not hold before save', async () => {
    authApi.hasPermission.mockImplementation((code: string) => code === 'qa.answer');
    const wrapper = mount(StaffOverrideView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    const unavailable = wrapper.findAll('button[title="当前账号未持有此权限"]');
    expect(unavailable).toHaveLength(4);
    expect(unavailable.every((button) => (button.element as HTMLButtonElement).disabled)).toBe(
      true,
    );
    expect(orgApi.setStaffOverrides).not.toHaveBeenCalled();
  });

  it('keeps a super administrator read-only and shows API errors', async () => {
    orgApi.getStaff.mockResolvedValueOnce({
      ...staff(),
      staff: { ...staff().staff, is_super_admin: true },
    });
    const wrapper = mount(StaffOverrideView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('超级管理员');
    const effectButtons = wrapper.findAll('td.actions button');
    expect(effectButtons).toHaveLength(6);
    expect(effectButtons.every((button) => (button.element as HTMLButtonElement).disabled)).toBe(
      true,
    );

    orgApi.getStaff.mockRejectedValueOnce({
      response: { data: { error: { code: 'FORBIDDEN', message: 'FORBIDDEN' } } },
    });
    routerApi.route!.params.id = '13';
    await flushPromises();
    expect(wrapper.text()).toContain('当前账号没有权限管理用户级覆盖');
  });
});
