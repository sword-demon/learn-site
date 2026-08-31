import { describe, expect, it } from 'vitest';
import { loginPathFor } from '@/router/guards';

describe('requireLearnerAuth redirect', () => {
  it('sends unauthenticated 我的学习 to learner login, not admin', () => {
    const path = loginPathFor('/me/learning');
    expect(path).toBe('/login?redirect=%2Fme%2Flearning');
    expect(path).not.toContain('/admin');
  });

  it('covers favorites, orders, and messages', () => {
    expect(loginPathFor('/me/favorites')).toContain('/login');
    expect(loginPathFor('/me/orders')).toContain('/login');
    expect(loginPathFor('/me/messages')).toContain('/login');
  });
});
