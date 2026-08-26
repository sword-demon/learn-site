import { describe, expect, it } from 'vitest';
import { AdminLoginInput, LearnerLoginInput, PhoneSchema, TokenPair } from '../auth.js';

describe('auth', () => {
  it('PhoneSchema accepts a valid 11-digit CN mobile', () => {
    expect(PhoneSchema.safeParse('13800138000').success).toBe(true);
  });

  it('PhoneSchema rejects a non-mobile string', () => {
    expect(PhoneSchema.safeParse('1234').success).toBe(false);
  });

  it('AdminLoginInput rejects 11-digit phone-shaped accounts (data-model rule)', () => {
    const result = AdminLoginInput.safeParse({
      account: '13800138000',
      password: 'password123',
      captcha_id: 'cap-1',
      captcha_answer: 'abcd',
    });
    expect(result.success).toBe(false);
  });

  it('LearnerLoginInput happy-path', () => {
    const result = LearnerLoginInput.safeParse({
      phone: '13800138000',
      password: 'password123',
      captcha_id: 'cap-1',
      captcha_answer: 'abcd',
    });
    expect(result.success).toBe(true);
  });

  it('TokenPair rejects non-positive expires_in', () => {
    expect(
      TokenPair.safeParse({
        access_token: 'a',
        access_expires_in: 0,
        refresh_token: 'r',
        refresh_expires_in: 60,
      }).success,
    ).toBe(false);
  });
});