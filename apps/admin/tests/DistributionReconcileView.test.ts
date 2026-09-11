// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchReconcileByOrder: vi.fn(),
  voidCommission: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);

import DistributionReconcileView from '@/views/distribution/DistributionReconcileView.vue';

describe('DistributionReconcileView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchReconcileByOrder.mockResolvedValue({
      order_id: 88,
      receivers: [
        {
          id: 3,
          referrer_masked_phone: '138****1234',
          level: 1,
          amount_cents: 100,
          status: 'pending',
        },
      ],
    });
  });

  it('queries an order and shows masked phones', async () => {
    const wrapper = mount(DistributionReconcileView, {
      global: { plugins: [installElementPlus] },
    });
    const input = wrapper.find('input');
    await input.setValue(88);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
    expect(api.fetchReconcileByOrder).toHaveBeenCalledWith(88);
    expect(wrapper.text()).toContain('138****1234');
    wrapper.unmount();
  });
});
