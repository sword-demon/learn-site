import { describe, expect, it } from 'vitest';
import { PaymentConfig, PaymentConfigUpdateInput } from '../paymentConfig.js';

const output = {
  enabled: true,
  api_url: 'https://z-pay.cn/',
  pid: '20220726190052',
  merchant_key_masked: '********GIJ',
  notify_url: 'https://learn.example.test/notify',
  return_url: 'https://learn.example.test/return',
  enabled_channels: ['wxpay', 'alipay'] as const,
  whitelist_only: false,
  version: 1,
  updated_at: '2026-09-04T10:00:00.000Z',
};

const input = {
  enabled: true,
  api_url: 'https://z-pay.cn/',
  pid: '20220726190052',
  merchant_key: 'merchant-secret',
  notify_url: 'https://learn.example.test/notify',
  return_url: 'https://learn.example.test/return',
  enabled_channels: ['wxpay'] as const,
  whitelist_only: false,
  version: 1,
};

describe('payment config contracts', () => {
  it('accepts masked output and rejects plaintext in the masked field', () => {
    expect(PaymentConfig.safeParse(output).success).toBe(true);
    expect(PaymentConfig.safeParse({ ...output, merchant_key_masked: 'merchant-secret' }).success).toBe(false);
    const parsed = PaymentConfig.parse({ ...output, merchant_key: 'merchant-secret' });
    expect(parsed).not.toHaveProperty('merchant_key');
  });

  it('accepts an empty key only as the preserve-existing update form', () => {
    expect(PaymentConfigUpdateInput.safeParse(input).success).toBe(true);
    expect(PaymentConfigUpdateInput.parse(input).version).toBe(1);
    expect(PaymentConfigUpdateInput.safeParse({ ...input, merchant_key: '' }).success).toBe(true);
  });

  it('rejects invalid API URL, pid, and channel values', () => {
    expect(PaymentConfigUpdateInput.safeParse({ ...input, api_url: 'http://z-pay.cn/' }).success).toBe(false);
    expect(PaymentConfigUpdateInput.safeParse({ ...input, api_url: 'https://z-pay.cn' }).success).toBe(false);
    expect(PaymentConfigUpdateInput.safeParse({ ...input, pid: 'short' }).success).toBe(false);
    expect(PaymentConfigUpdateInput.safeParse({ ...input, enabled_channels: [] }).success).toBe(false);
    expect(PaymentConfigUpdateInput.safeParse({ ...input, enabled_channels: ['stripe'] }).success).toBe(false);
  });
});
