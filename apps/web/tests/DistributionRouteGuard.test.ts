// @vitest-environment happy-dom

import { describe, expect, it, vi, beforeEach } from 'vitest';

const api = vi.hoisted(() => ({
  fetchDistributionStatus: vi.fn(),
}));

vi.mock('@/api/distribution', () => api);
vi.mock('@/api/http', () => ({
  hasTokens: vi.fn(() => true),
}));

import { distributionGate } from '@/router/guards';

describe('distribution route guard (FR-014)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('redirects to home when the site-wide switch is off', async () => {
    api.fetchDistributionStatus.mockResolvedValue({
      enabled: false,
      learner_can_view_detail: true,
    });
    const result = await distributionGate(
      { fullPath: '/me/distribution' } as never,
      undefined as never,
      undefined as never,
    );
    expect(result).toEqual({ path: '/' });
    expect(api.fetchDistributionStatus).toHaveBeenCalledOnce();
  });

  it('lets the learner through when distribution is enabled', async () => {
    api.fetchDistributionStatus.mockResolvedValue({ enabled: true, learner_can_view_detail: true });
    const result = await distributionGate(
      { fullPath: '/me/distribution' } as never,
      undefined as never,
      undefined as never,
    );
    expect(result).toBe(true);
  });

  it('fails closed when the status endpoint errors', async () => {
    api.fetchDistributionStatus.mockRejectedValue(new Error('NETWORK'));
    const result = await distributionGate(
      { fullPath: '/me/distribution' } as never,
      undefined as never,
      undefined as never,
    );
    expect(result).toEqual({ path: '/' });
  });

  it('redirects to login when there is no session', async () => {
    const { hasTokens } = await import('@/api/http');
    (hasTokens as ReturnType<typeof vi.fn>).mockReturnValue(false);
    const result = await distributionGate(
      { fullPath: '/me/distribution' } as never,
      undefined as never,
      undefined as never,
    );
    expect(result).toBe('/login?redirect=%2Fme%2Fdistribution');
  });
});
