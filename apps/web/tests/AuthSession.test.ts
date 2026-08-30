// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { AUTH_STORAGE_KEY } from '@/api/http';

const pair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
};

describe('learner auth session persistence', () => {
  beforeEach(() => {
    localStorage.clear();
    vi.resetModules();
  });

  afterEach(() => {
    localStorage.clear();
  });

  it('writes tokens so a full page reload can restore the session', async () => {
    const { setTokens } = await import('@/api/http');
    setTokens(pair);

    const stored = localStorage.getItem(AUTH_STORAGE_KEY);
    expect(stored).not.toBeNull();
    expect(JSON.parse(stored ?? '')).toMatchObject({
      access: pair.access_token,
      refresh: pair.refresh_token,
      mustChangePassword: false,
    });
  });

  it('hydrates in-memory auth from localStorage on a fresh module load', async () => {
    localStorage.setItem(
      AUTH_STORAGE_KEY,
      JSON.stringify({
        access: pair.access_token,
        refresh: pair.refresh_token,
        mustChangePassword: false,
      }),
    );

    const { hasTokens } = await import('@/api/http');
    expect(hasTokens()).toBe(true);
  });

  it('clears persisted tokens on logout', async () => {
    const { setTokens, clearTokens, hasTokens } = await import('@/api/http');
    setTokens(pair);
    clearTokens();

    expect(hasTokens()).toBe(false);
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).toBeNull();
  });
});
