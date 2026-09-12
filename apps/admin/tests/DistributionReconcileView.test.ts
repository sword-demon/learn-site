// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchCommissions: vi.fn(),
  fetchReconcileByOrder: vi.fn(),
  voidCommission: vi.fn(),
  exportCommissionsCsv: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);

import DistributionReconcileView from '@/views/distribution/DistributionReconcileView.vue';

const commission = {
  id: 3,
  order_id: 88,
  course_id: 1,
  course_title: '摄影入门',
  referee_masked_phone: '138****1234',
  level: 1 as const,
  amount_cents: 100,
  status: 'pending' as const,
  source: 'system_settle' as const,
  created_at: '2026-09-10T12:00:00+08:00',
  settled_at: null,
  voided_at: null,
  void_reason: null,
};

describe('DistributionReconcileView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchCommissions.mockResolvedValue({
      items: [commission],
      total: 1,
      page: 1,
      limit: 20,
    });
    api.fetchReconcileByOrder.mockResolvedValue({
      order_id: 88,
      course_id: 1,
      order_paid_cents_snapshot: 9900,
      config_snapshot: {},
      receivers: [
        {
          id: 3,
          referrer_learner_id: 9,
          referrer_masked_phone: '138****1234',
          level: 1,
          amount_cents: 100,
          status: 'pending',
          source: 'system_settle',
          settled_at: null,
          voided_at: null,
          void_reason: null,
        },
      ],
    });
  });

  it('loads commissions and shows masked phones', async () => {
    const wrapper = mount(DistributionReconcileView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(api.fetchCommissions).toHaveBeenCalled();
    expect(wrapper.text()).toContain('138****1234');
    wrapper.unmount();
  });

  it('queries an order and shows masked phones', async () => {
    const wrapper = mount(DistributionReconcileView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    const input = wrapper.get('[data-field="order_id"]');
    await input.setValue('88');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.fetchCommissions).toHaveBeenCalledWith(expect.objectContaining({ order_id: 88 }));
    expect(api.fetchReconcileByOrder).toHaveBeenCalledWith(88);
    expect(wrapper.text()).toContain('138****1234');
    wrapper.unmount();
  });

  it('treats a missing order as empty instead of crashing', async () => {
    api.fetchReconcileByOrder.mockResolvedValue(null);
    const wrapper = mount(DistributionReconcileView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-field="order_id"]').setValue('99');
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(wrapper.text()).toContain('该订单没有佣金记录');
    wrapper.unmount();
  });
});
