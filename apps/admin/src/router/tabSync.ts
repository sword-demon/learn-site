import type { RouteLocationNormalized } from 'vue-router';

/** True when the matched route tree includes the AdminLayout shell at `/`. */
export function isAdminLayoutChild(route: RouteLocationNormalized): boolean {
  const layout = route.matched.find((record) => record.path === '/');
  if (!layout) return false;
  return route.matched.length > 1 || route.path === '/';
}

/** Whether navigation should open or update an entry in the tab bar. */
export function shouldTrackTab(route: RouteLocationNormalized): boolean {
  if (route.meta.hideInTabs === true) return false;
  if (route.name === 'login' || route.name === 'first-password') return false;
  return isAdminLayoutChild(route);
}

/** Whether global route loading feedback should run for this navigation target. */
export function shouldShowRouteLoading(route: RouteLocationNormalized): boolean {
  return isAdminLayoutChild(route);
}
