// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchOrder: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { orderId: '100' } }),
  useRouter: () => ({ push: vi.fn() }),
}));

import OrderDetailView from '@/views/me/OrderDetailView.vue';

const baseOrder = {
  order_id: 100,
  course_id: 7,
  course_title: 'Webman 实战',
  list_price_snapshot: 199,
  sale_price_snapshot: 149,
  coupon_discount_snapshot: 20,
  paid_amount: 129,
  learner_coupon_id: 8,
  currency: 'CNY',
  status: 'succeeded' as const,
  provider: 'fake',
  succeeded_at: '2026-08-20T10:01:00+08:00',
  created_at: '2026-08-20T10:00:00+08:00',
};

beforeEach(() => {
  vi.clearAllMocks();
  learnerApi.fetchOrder.mockResolvedValue(baseOrder);
});

describe('OrderDetailView', () => {
  const global = {
    stubs: { RouterLink: { props: ['to'], template: '<a :href="String(to)"><slot /></a>' } },
  };

  it('loads and displays the immutable order snapshot', async () => {
    const wrapper = mount(OrderDetailView, { global });
    await flushPromises();

    expect(learnerApi.fetchOrder).toHaveBeenCalledWith(100);
    expect(wrapper.text()).toContain('订单详情');
    expect(wrapper.text()).toContain('Webman 实战');
    expect(wrapper.text()).toContain('标准价');
    expect(wrapper.text()).toContain('¥ 199.00');
    expect(wrapper.text()).toContain('优惠券抵扣');
    expect(wrapper.text()).toContain('¥ 20.00');
    expect(wrapper.text()).toContain('实付金额');
    expect(wrapper.text()).toContain('¥ 129.00');
    expect(wrapper.text()).toContain('支付成功');
    expect(wrapper.text()).toContain('进入课程');
    expect(wrapper.get('a.action-primary').attributes('href')).toBe('/courses/7');
  });

  it.each([
    ['pending', '继续支付'],
    ['failed', '重新购买'],
    ['cancelled', '重新购买'],
  ] as const)('shows the correct action for %s orders', async (status, action) => {
    learnerApi.fetchOrder.mockResolvedValue({ ...baseOrder, status });
    const wrapper = mount(OrderDetailView, { global });
    await flushPromises();

    expect(wrapper.text()).toContain(action);
    expect(wrapper.get('a.action-primary').attributes('href')).toBe('/checkout/7');
  });

  it('shows a friendly message when the order cannot be loaded', async () => {
    learnerApi.fetchOrder.mockRejectedValueOnce(new Error('ORDER_NOT_FOUND'));
    const wrapper = mount(OrderDetailView, { global });
    await flushPromises();

    expect(wrapper.text()).toContain('订单不存在或无权查看');
  });
});
