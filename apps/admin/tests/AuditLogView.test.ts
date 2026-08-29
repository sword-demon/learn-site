// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const auditApi = vi.hoisted(() => ({
  listModerationLogs: vi.fn(),
  restoreModeratedContent: vi.fn(),
}));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));
const orgApi = vi.hoisted(() => ({ listStaff: vi.fn() }));

vi.mock('@/api/audit', () => auditApi);
vi.mock('@/api/http', () => authApi);
vi.mock('@/api/org', () => orgApi);

import AuditLogView from '@/views/site/AuditLogView.vue';

describe('AuditLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasPermission.mockReturnValue(true);
    orgApi.listStaff.mockResolvedValue({
      items: [
        {
          account_id: 7,
          login: 'review-admin',
          display_name: '审核管理员',
          is_super_admin: false,
          department_id: 1,
          department_name: '运营',
          department_status: 'enabled',
          account_status: 'active',
          must_change_password: false,
          last_login_at: '',
          created_at: '2026-01-01 00:00:00',
          updated_at: '2026-01-01 00:00:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    auditApi.listModerationLogs.mockResolvedValue({
      items: [
        {
          id: 9,
          object_type: 'review',
          object_id: 56,
          action: 'hide',
          reason: '误含广告链接',
          staff_id: 7,
          staff_login: 'review-admin',
          restorable: true,
          created_at: '2026-08-28 10:00:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    auditApi.restoreModeratedContent.mockResolvedValue(undefined);
  });

  it('shows moderation facts and restores a currently hidden object', async () => {
    const wrapper = mount(AuditLogView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('误含广告链接');
    expect(wrapper.text()).toContain('review-admin');
    expect(auditApi.listModerationLogs).toHaveBeenCalledWith({ page: 1, limit: 20 });
    await wrapper.get('button[data-action="restore"]').trigger('click');
    await flushPromises();

    expect(auditApi.restoreModeratedContent).toHaveBeenCalledWith({
      object_type: 'review',
      object_id: 56,
    });
    expect(auditApi.listModerationLogs).toHaveBeenCalledTimes(2);
  });

  it('hides restoration without review moderation permission', async () => {
    authApi.hasPermission.mockImplementation((code: string) => code === 'audit.view');
    const wrapper = mount(AuditLogView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.find('button[data-action="restore"]').exists()).toBe(false);
  });
});
