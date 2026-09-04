import { describe, expect, it } from 'vitest';
import {
  PaymentWhitelistCreateInput,
  PaymentWhitelistEntry,
  PaymentWhitelistListResponse,
} from '../paymentWhitelist.js';

const entry = {
  id: 1,
  phone_masked: '138****1234',
  enabled: true,
  note: '运营测试',
  created_at: '2026-09-04T10:00:00.000Z',
  updated_at: '2026-09-04T10:00:00.000Z',
};

describe('payment whitelist contracts', () => {
  it('accepts masked entries and paginated responses', () => {
    expect(PaymentWhitelistEntry.safeParse(entry).success).toBe(true);
    expect(
      PaymentWhitelistListResponse.safeParse({ items: [entry], total: 1, page: 1, limit: 20 }).success,
    ).toBe(true);
  });

  it('defaults enabled and enforces the 11-digit phone contract', () => {
    expect(PaymentWhitelistCreateInput.parse({ phone: '10000000000' })).toEqual({
      phone: '10000000000',
      enabled: true,
    });
    expect(PaymentWhitelistCreateInput.safeParse({ phone: '20000000000' }).success).toBe(false);
    expect(PaymentWhitelistCreateInput.safeParse({ phone: '1380000123' }).success).toBe(false);
  });
});
