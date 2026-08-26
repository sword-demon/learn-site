// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { AdminOrderDTO } from '@learn-site/contracts';

const orderApi = vi.hoisted(() => ({
  getOrder: vi.fn(),
  listOrders: vi.fn(),
}));

vi.mock('@/api/orders', () => orderApi);

import OrderListView from '@/views/orders/OrderListView.vue';

const order: AdminOrderDTO = {
  order_id: 42,
  course_id: 12,
  course_title: 'TypeScript 深入实践',
  learner_id: 78,
  department_id: 3,
  list_price_snapshot: 199,
  sale_price_snapshot: 149,
  paid_amount: 149,
  currency: 'CNY',
  status: 'succeeded',
  provider: 'fake',
  provider_ref: 'fake-42',
  succeeded_at: '2026-08-25 10:30:00',
  created_at: '2026-08-25 10:29:00',
  failed_reason: null,
};

describe('OrderListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    orderApi.listOrders.mockResolvedValue({
      items: [order],
      total: 1,
      page: 1,
      limit: 20,
    });
    orderApi.getOrder.mockResolvedValue(order);
  });

  it('renders the order summary and immutable payment snapshot', async () => {
    const wrapper = mount(OrderListView);
    await flushPromises();

    expect(wrapper.text()).toContain('TypeScript 深入实践');
    expect(wrapper.text()).toContain('#78');
    expect(wrapper.text()).toContain('CNY 149.00');
    expect(wrapper.text()).toContain('已支付');
    expect(wrapper.text()).toContain('2026-08-25 10:29:00');

    await wrapper.get('tbody tr.row').trigger('click');
    await flushPromises();

    expect(orderApi.getOrder).toHaveBeenCalledWith(42);
    const detail = wrapper.get('.right-pane').text();
    expect(detail).toContain('标价CNY 199.00');
    expect(detail).toContain('售价CNY 149.00');
    expect(detail).toContain('实付CNY 149.00');
    expect(detail).toContain('fake / fake-42');
    expect(detail).toContain('2026-08-25 10:30:00');
  });

  it('keeps a detail loading failure visible when no order is active', async () => {
    orderApi.getOrder.mockRejectedValueOnce(new Error('DETAIL_UNAVAILABLE'));
    const wrapper = mount(OrderListView);
    await flushPromises();

    await wrapper.get('tbody tr.row').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('DETAIL_UNAVAILABLE');
  });

  it('returns to the first page when applying a status filter', async () => {
    orderApi.listOrders.mockImplementation(async (params: { page: number; limit: number }) => ({
      items: [order],
      total: 40,
      page: params.page,
      limit: params.limit,
    }));
    const wrapper = mount(OrderListView);
    await flushPromises();

    const next = wrapper.findAll('button').find((button) => button.text() === '下一页');
    expect(next).toBeDefined();
    await next?.trigger('click');
    await flushPromises();

    await wrapper.get('.filters select').setValue('succeeded');
    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(orderApi.listOrders).toHaveBeenLastCalledWith({
      status: 'succeeded',
      page: 1,
      limit: 20,
    });
  });

  it('does not request a fractional course ID', async () => {
    const wrapper = mount(OrderListView);
    await flushPromises();

    await wrapper.get<HTMLInputElement>('input[name="course_id"]').setValue('1.2');
    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(orderApi.listOrders).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain('课程 ID 必须为正整数');
  });
});
