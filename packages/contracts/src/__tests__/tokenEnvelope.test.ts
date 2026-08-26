import { describe, expect, it } from 'vitest';
import { parseTokenEnvelope } from '../tokenEnvelope.js';

describe('tokenEnvelope', () => {
  it('happy-path: parses TokenPair + must_change_password + permission_codes', () => {
    const data = {
      ok: true,
      data: {
        access_token: 'eyJ.eyJ.sig',
        access_expires_in: 900,
        refresh_token: 'r1',
        refresh_expires_in: 604800,
        must_change_password: false,
        permission_codes: ['category.manage', 'course.view'],
      },
      error: null,
    };
    const parsed = parseTokenEnvelope(data);
    expect(parsed).not.toBeNull();
    expect(parsed?.access_token).toBe('eyJ.eyJ.sig');
    expect(parsed?.permission_codes).toEqual(['category.manage', 'course.view']);
  });

  it('happy-path: tolerates absence of optional flags', () => {
    const data = {
      ok: true,
      data: {
        access_token: 'eyJ.eyJ.sig',
        access_expires_in: 900,
        refresh_token: 'r1',
        refresh_expires_in: 604800,
      },
      error: null,
    };
    expect(parseTokenEnvelope(data)?.must_change_password).toBeUndefined();
  });

  it('sad-path: rejects an err envelope', () => {
    const data = { ok: false, data: null, error: { code: 'TOKEN_EXPIRED', message: 'expired' } };
    expect(parseTokenEnvelope(data)).toBeNull();
  });

  it('sad-path: rejects a malformed envelope', () => {
    expect(parseTokenEnvelope({ ok: 'yes' })).toBeNull();
  });
});