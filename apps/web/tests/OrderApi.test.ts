import { beforeEach, describe, expect, it, vi } from 'vitest';

const httpApi = vi.hoisted(() => ({
  post: vi.fn(),
  get: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http: httpApi }));

import { createCourseOrder, fetchOrder } from '@/api/learner';

describe('learner order API', () => {
  beforeEach(() => vi.clearAllMocks());

  it('creates a pending order using the learner API envelope', async () => {
    httpApi.post.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          order_id: 42,
          status: 'pending',
          list_price_snapshot: 99,
          sale_price_snapshot: 0,
          coupon_discount_snapshot: 0,
          paid_amount: 99,
          learner_coupon_id: null,
          payment: {
            type: 'wechat_native',
            code_url: 'fake://wechat-native?order_id=42',
          },
        },
      },
    });

    await expect(createCourseOrder(7)).resolves.toMatchObject({ order_id: 42, status: 'pending' });
    expect(httpApi.post).toHaveBeenCalledWith('/courses/7/orders', {});
  });

  it('forwards the optional learner coupon id when creating an order', async () => {
    httpApi.post.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          order_id: 43,
          status: 'pending',
          list_price_snapshot: 99,
          sale_price_snapshot: 0,
          coupon_discount_snapshot: 15,
          paid_amount: 84,
          learner_coupon_id: 7,
          payment: {
            type: 'wechat_native',
            code_url: 'fake://wechat-native?order_id=43',
          },
        },
      },
    });

    await expect(createCourseOrder(7, 99)).resolves.toMatchObject({
      order_id: 43,
      paid_amount: 84,
    });
    expect(httpApi.post).toHaveBeenCalledWith('/courses/7/orders', { learner_coupon_id: 99 });
  });

  it('preserves a non-success payment state for the checkout page', async () => {
    httpApi.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          order_id: 42,
          course_id: 7,
          list_price_snapshot: 99,
          sale_price_snapshot: 0,
          coupon_discount_snapshot: 0,
          paid_amount: 99,
          learner_coupon_id: null,
          currency: 'CNY',
          status: 'unknown',
          provider: 'fake',
          succeeded_at: null,
          created_at: '2026-08-28 10:00:00',
        },
      },
    });

    await expect(fetchOrder(42)).resolves.toMatchObject({ order_id: 42, status: 'unknown' });
    expect(httpApi.get).toHaveBeenCalledWith('/orders/42');
  });
});
