export interface AdminNavigationTarget {
  name: string
  path: string
  fullPath: string
  public: boolean
  permission?: string
}

export interface AdminAuthSnapshot {
  hasTokens: boolean
  mustChangePassword: boolean
  permissionCodes: readonly string[]
  fallbackPath: string | null
}

export type AdminNavigationDecision =
  | true
  | { name: 'login'; query: { next: string } }
  | { path: string; query?: { from: string } | { next: string } }

function hasPermission(codes: readonly string[], required: string): boolean {
  return codes.includes('*') || codes.includes(required)
}

/** Pure route-policy decision used by the Vue Router guard and unit tests. */
export function resolveAdminNavigation(
  target: AdminNavigationTarget,
  auth: AdminAuthSnapshot,
): AdminNavigationDecision {
  if (auth.mustChangePassword) {
    if (target.name === 'first-password') {
      return auth.hasTokens
        ? true
        : { name: 'login', query: { next: target.fullPath } }
    }
    return { path: '/first-password', query: { next: target.fullPath } }
  }

  if (target.name === 'first-password') {
    return auth.hasTokens
      ? { path: auth.fallbackPath ?? '/forbidden' }
      : { name: 'login', query: { next: target.fullPath } }
  }

  if (target.public) {
    if (target.name === 'login' && auth.hasTokens) {
      return { path: auth.fallbackPath ?? '/forbidden' }
    }
    return true
  }

  if (!auth.hasTokens) {
    return { name: 'login', query: { next: target.fullPath } }
  }

  const required = target.permission
  if (required === undefined || hasPermission(auth.permissionCodes, required)) {
    return true
  }

  // A limited account should land on a module it can use, not immediately on
  // an error because the dashboard is independently permissioned.
  if (target.path === '/' && auth.fallbackPath !== null && auth.fallbackPath !== '/') {
    return { path: auth.fallbackPath }
  }

  return { path: '/forbidden', query: { from: target.fullPath } }
}
