import { computed, type ComputedRef } from 'vue';
import type { RouteLocationNormalizedLoaded } from 'vue-router';
import type { AdminMenuEntry, AdminMenuGroup } from '@/layouts/AdminMenu';

export interface BreadcrumbItem {
  title: string;
  path?: string;
}

interface MenuMatch {
  path: string;
  title: string;
  parentTitle?: string;
}

function isGroup(entry: AdminMenuEntry): entry is AdminMenuGroup {
  return Array.isArray((entry as AdminMenuGroup).children);
}

function collectMenuMatches(entries: readonly AdminMenuEntry[]): MenuMatch[] {
  const matches: MenuMatch[] = [];

  for (const entry of entries) {
    if (isGroup(entry)) {
      for (const child of entry.children) {
        matches.push({
          path: child.path,
          title: child.label,
          parentTitle: entry.label,
        });
      }
      continue;
    }
    matches.push({ path: entry.path, title: entry.label });
  }

  return matches;
}

function longestMenuMatch(routePath: string, entries: readonly AdminMenuEntry[]): MenuMatch | null {
  let best: MenuMatch | null = null;

  for (const item of collectMenuMatches(entries)) {
    if (routePath !== item.path && !routePath.startsWith(`${item.path}/`)) continue;
    if (!best || item.path.length > best.path.length) {
      best = item;
    }
  }

  return best;
}

/** Pure breadcrumb resolver for views, stores, and unit tests. */
export function resolveAdminBreadcrumb(
  routePath: string,
  meta: { title?: string; breadcrumb?: BreadcrumbItem[] },
  menuEntries: readonly AdminMenuEntry[],
): BreadcrumbItem[] {
  if (Array.isArray(meta.breadcrumb) && meta.breadcrumb.length > 0) {
    return meta.breadcrumb;
  }

  const pageTitle =
    typeof meta.title === 'string' && meta.title.length > 0 ? meta.title : '未命名页面';
  const match = longestMenuMatch(routePath, menuEntries);

  if (!match) {
    return [{ title: pageTitle }];
  }

  const crumbs: BreadcrumbItem[] = [];

  if (match.parentTitle) {
    crumbs.push({ title: match.parentTitle });
  }

  const isExactLeaf = routePath === match.path;

  if (!isExactLeaf || match.title !== pageTitle) {
    if (!isExactLeaf) {
      crumbs.push({ title: match.title, path: match.path });
    }
    if (pageTitle !== match.title || isExactLeaf) {
      crumbs.push({ title: pageTitle });
    }
  } else {
    crumbs.push({ title: match.title });
  }

  return crumbs.length > 0 ? crumbs : [{ title: pageTitle }];
}

export function useAdminBreadcrumb(
  route: RouteLocationNormalizedLoaded,
  menuEntries: ComputedRef<readonly AdminMenuEntry[]>,
): ComputedRef<BreadcrumbItem[]> {
  return computed(() => resolveAdminBreadcrumb(route.path, route.meta, menuEntries.value));
}
