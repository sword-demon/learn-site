// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
const api = vi.hoisted(() => ({
  fetchDistributionStatus: vi.fn(),
  fetchMyShareEntries: vi.fn(),
  fetchMyCommissions: vi.fn(),
  fetchMyDownline: vi.fn(),
  createShareEntry: vi.fn(),
  revokeShareEntry: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);
vi.mock('vue-router', () => ({
  useRoute: () => ({ path: '/me/distribution' }),
}));

import DistributionView from '@/views/DistributionView.vue';

describe('DistributionView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchDistributionStatus.mockResolvedValue({ enabled: true, learner_can_view_detail: true });
    api.fetchMyShareEntries.mockResolvedValue({ items: [] });
    api.fetchMyCommissions.mockResolvedValue({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
      summary: { pending_cents: 0, settled_cents: 0, voided_cents: 0, total_cents: 0 },
    });
    api.fetchMyDownline.mockResolvedValue({ items: [] });
  });

  it('renders sections without plaintext phones', async () => {
    api.fetchMyDownline.mockResolvedValue({
      items: [
        {
          learner_id: 2,
          masked_phone: '138****1234',
          level: 1,
          registered_at: '2026-09-10T00:00:00+08:00',
        },
      ],
    });
    const wrapper = mount(DistributionView);
    await flushPromises();
    expect(wrapper.text()).toContain('我的分销');
    expect(wrapper.text()).toContain('138****1234');
    expect(wrapper.text()).not.toMatch(/138\d{8}/);
    wrapper.unmount();
  });

  it('hides detail when the learner detail switch is off', async () => {
    api.fetchDistributionStatus.mockResolvedValue({
      enabled: true,
      learner_can_view_detail: false,
    });
    const wrapper = mount(DistributionView);
    await flushPromises();
    expect(wrapper.text()).toContain('佣金明细当前不可查看');
    expect(api.fetchMyShareEntries).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
