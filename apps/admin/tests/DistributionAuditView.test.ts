// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchAudit: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);

import DistributionAuditView from '@/views/distribution/DistributionAuditView.vue';

describe('DistributionAuditView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchAudit.mockResolvedValue({
      items: [
        {
          id: 1,
          actor_type: 'admin',
          actor_id: 9,
          action: 'config.update',
          subject_type: 'config',
          subject_id: null,
          before_json: null,
          after_json: { enabled: true },
          reason: null,
          created_at: '2026-09-10 12:00:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
  });

  it('renders audit actions', async () => {
    const wrapper = mount(DistributionAuditView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('config.update');
    expect(wrapper.text()).toContain('更新配置');
    expect(api.fetchAudit).toHaveBeenCalled();
    wrapper.unmount();
  });

  it('filters by action', async () => {
    const wrapper = mount(DistributionAuditView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const action = wrapper.find('[data-field="action"]');
    expect(action.exists()).toBe(true);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.fetchAudit).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });
});
