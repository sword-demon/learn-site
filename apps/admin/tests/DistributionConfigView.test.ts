// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchDistributionConfig: vi.fn(),
  saveDistributionConfig: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);

import DistributionConfigView from '@/views/distribution/DistributionConfigView.vue';

const cfg = {
  enabled: true,
  level_cap: 3 as const,
  level1_pct: 0.1,
  level2_pct: 0.05,
  level3_pct: 0.02,
  base: 'order_paid' as const,
  per_order_cap_cents: 5000,
  per_learner_course_cap_cents: 20000,
  per_learner_total_cap_cents: null,
  settlement: 'order_settled_after_refund_window' as const,
  refund_void_rule: 'void_all' as const,
  payout_form: 'cash_record_only' as const,
  learner_can_view_detail: true,
  updated_at: '2026-09-10T00:00:00+08:00',
  updated_by: 1,
};

describe('DistributionConfigView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchDistributionConfig.mockResolvedValue(cfg);
    api.saveDistributionConfig.mockResolvedValue(cfg);
  });

  it('shows 合规硬约束 near level cap', async () => {
    const wrapper = mount(DistributionConfigView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('合规硬约束');
    wrapper.unmount();
  });

  it('saves a legal config', async () => {
    const wrapper = mount(DistributionConfigView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.saveDistributionConfig).toHaveBeenCalled();
    wrapper.unmount();
  });
});
