import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp }));

import { getOrder, listOrders } from '@/api/orders';

const order = {
  order_id: 42,
  course_id: 12,
  course_title: 'TypeScript 深入实践',
  learner_id: 78,
  department_id: 3,
  list_price_snapshot: 199,
  sale_price_snapshot: 149,
  coupon_discount_snapshot: 0,
  paid_amount: 149,
  learner_coupon_id: null,
  currency: 'CNY',
  status: 'succeeded',
  provider: 'fake',
  provider_ref: 'fake-42',
  succeeded_at: '2026-08-25 10:30:00',
  created_at: '2026-08-25 10:29:00',
  failed_reason: null,
};

describe('admin order API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('unwraps a validated order-list response and forwards filters', async () => {
    const list = { items: [order], total: 1, page: 2, limit: 20 };
    mockHttp.get.mockResolvedValueOnce({ data: { ok: true, data: list } });

    await expect(
      listOrders({
        status: 'succeeded',
        course_id: 12,
        from: '2026-08-25',
        to: '2026-08-25',
        page: 2,
        limit: 20,
      }),
    ).resolves.toEqual(list);
    expect(mockHttp.get).toHaveBeenCalledWith('/orders', {
      params: {
        status: 'succeeded',
        course_id: 12,
        from: '2026-08-25',
        to: '2026-08-25',
        page: 2,
        limit: 20,
      },
    });
  });

  it('unwraps a validated order detail response', async () => {
    mockHttp.get.mockResolvedValueOnce({ data: { ok: true, data: order } });

    await expect(getOrder(order.order_id)).resolves.toEqual(order);
    expect(mockHttp.get).toHaveBeenCalledWith('/orders/42');
  });

  it('rejects a malformed order response before it reaches the view', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          items: [{ ...order, paid_amount: '149.00' }],
          total: 1,
          page: 1,
          limit: 20,
        },
      },
    });

    await expect(listOrders()).rejects.toThrow();
  });
});
