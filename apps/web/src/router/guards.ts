import type { NavigationGuard } from 'vue-router'
import { hasTokens } from '@/api/http'

export function loginPathFor(target: string): string {
  return `/login?redirect=${encodeURIComponent(target)}`
}

export const requireLearnerAuth: NavigationGuard = (to) => {
  if (hasTokens()) {
    return true
  }
  return loginPathFor(to.fullPath)
}
