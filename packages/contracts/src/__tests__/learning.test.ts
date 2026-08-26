import { describe, expect, it } from 'vitest';
import { CreateOrderResponseDTO, OrderStatus, PaymentEnvelopeDTO } from '../learning.js';

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
      payment: { type: 'native', code_url: 'weixin://wxpay/bizpayurl?pr=abc' },
    });
    expect(result.success).toBe(true);
  });
});