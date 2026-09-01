import { describe, expect, it, vi } from 'vitest';
import { PUSH_SCRIPT_URL } from '@/utils/push';

vi.mock('@/utils/push', async () => {
  const actual = await vi.importActual<typeof import('@/utils/push')>('@/utils/push');
  return actual;
});

describe('push connection options', () => {
  it('cache-busts the patched vendor script', () => {
    expect(PUSH_SCRIPT_URL).toBe('/plugin/webman/push/push.js?v=auth');
  });

  it('documents auth header and body token fields used by patched push.js', () => {
    const options = {
      url: 'ws://push.test',
      appKey: 'test-key',
      auth: '/plugin/webman/push/auth',
      authHeader: { Authorization: 'Bearer token-abc' },
      authData: { access_token: 'token-abc' },
    };

    expect(options.authHeader.Authorization).toBe('Bearer token-abc');
    expect(options.authData.access_token).toBe('token-abc');
  });
});
