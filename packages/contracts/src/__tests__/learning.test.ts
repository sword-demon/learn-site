import { describe, expect, it } from 'vitest';
import {
  CreateOrderResponseDTO,
  MyLearningItemDTO,
  OrderStatus,
  PaymentEnvelopeDTO,
} from '../learning.js';

describe('learning', () => {
  it('OrderStatus stays enum-locked', () => {
    expect(OrderStatus.options).toEqual(['pending', 'succeeded', 'failed', 'cancelled', 'unknown']);
  });

  it('PaymentEnvelopeDTO requires type + code_url', () => {
    expect(
      PaymentEnvelopeDTO.safeParse({ type: 'native', code_url: 'weixin://wxpay/bizpayurl?pr=abc' })
        .success,
    ).toBe(true);
    expect(PaymentEnvelopeDTO.safeParse({ type: 'native' }).success).toBe(false);
  });

  it('CreateOrderResponseDTO happy-path', () => {
    const result = CreateOrderResponseDTO.safeParse({
      order_id: 42,
      status: 'pending',
      list_price_snapshot: 199,
      sale_price_snapshot: 199,
      paid_amount: 199,
      payment: { type: 'native', code_url: 'weixin://wxpay/bizpayurl?pr=abc' },
    });
    expect(result.success).toBe(true);
  });

  it('MyLearningItemDTO carries revoked access and rejoin state', () => {
    const item = MyLearningItemDTO.parse({
      course_id: 7,
      progress_percent: 40,
      last_lesson_id: 12,
      last_position: 120,
      completed_at: null,
      updated_at: '2026-08-28 11:00:00',
      entitlement_status: 'revoked',
      entitlement_source: 'free',
      revoked_at: '2026-08-28 11:00:00',
      revoked_reason: '误加入课程',
      can_rejoin: true,
      course: {
        id: 7,
        title: 'Webman 实战',
        cover_url: null,
        teacher_name: '林老师',
        status: 'published',
        price_mode: 'free',
      },
    });

    expect(item.entitlement_status).toBe('revoked');
    expect(item.can_rejoin).toBe(true);
  });
});
