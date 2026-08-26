import { describe, expect, it } from 'vitest';
import { z } from 'zod';
import { ApiOk, ApiResponse, ErrorCode } from '../envelope.js';

describe('envelope', () => {
  it('ApiOk round-trips a typed payload', () => {
    const Shape = z.object({ id: z.number() });
    expect(ApiOk(Shape).parse({ ok: true, data: { id: 1 }, error: null }).data).toEqual({ id: 1 });
  });

  it('ApiResponse rejects malformed envelopes', () => {
    const Shape = z.object({ x: z.number() });
    expect(ApiResponse(Shape).safeParse({ ok: 'yes' }).success).toBe(false);
  });

  it('ErrorCode keeps the stable 10 codes', () => {
    expect(ErrorCode.options).toEqual([
      'CAPTCHA_INVALID',
      'TOKEN_EXPIRED',
      'TOKEN_REVOKED',
      'UNAUTHENTICATED',
      'FORBIDDEN',
      'NOT_FOUND',
      'VALIDATION_FAILED',
      'LOGIN_INVALID',
      'RATE_LIMITED',
      'INTERNAL',
    ]);
  });
});