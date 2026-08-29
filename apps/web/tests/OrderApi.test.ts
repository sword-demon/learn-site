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
          payment: {
            type: 'wechat_native',
            code_url: 'fake://wechat-native?order_id=42',
          },
        },
      },
    });

    await expect(createCourseOrder(7)).resolves.toMatchObject({ order_id: 42, status: 'pending' });
    expect(httpApi.post).toHaveBeenCalledWith('/courses/7/orders');
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
          paid_amount: 99,
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
