import type { NavigationGuard } from 'vue-router';
import { fetchDistributionStatus } from '@/api/distribution';
import { hasTokens } from '@/api/http';

export function loginPathFor(target: string): string {
  return `/login?redirect=${encodeURIComponent(target)}`;
}

export const requireLearnerAuth: NavigationGuard = (to) => {
  if (hasTokens()) {
    return true;
  }
  return loginPathFor(to.fullPath);
};

/**
 * FR-014 — when the site-wide distribution switch is off the learner-side
 * entry disappears entirely: navigating to /me/distribution lands on home
 * instead of a dead page. Admin-side routes are deliberately not gated.
 */
export const distributionGate: NavigationGuard = async (to) => {
  if (!hasTokens()) {
    return loginPathFor(to.fullPath);
  }
  try {
    const status = await fetchDistributionStatus();
    if (!status.enabled) {
      return { path: '/' };
    }
    return true;
  } catch {
    // Status unavailable — fail closed so a broken backend never exposes
    // a half-configured distribution page.
    return { path: '/' };
  }
};
