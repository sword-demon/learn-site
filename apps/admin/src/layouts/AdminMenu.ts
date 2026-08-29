// AdminMenu — single source of truth for sidebar items in the admin SPA.
//
// US13 / T059: when a staff account lacks a permission code, the menu entry
// pointing at that code must be hidden. The route guard (T060) is the second
// line of defence: a user who lands on a URL directly still gets bounced
// back to the dashboard. The menu filter is the visible piece.
//
// Items here mirror the route metadata and the server's Authorize middleware.
// Keep this list limited to stable module entry points; detail routes inherit
// their own, sometimes narrower, permission in router/index.ts.

export interface AdminMenuLeaf {
  /** el-menu item index; must match the vue-router path. */
  path: string;
  label: string;
  /** Permission code required to see the entry. `undefined` = always visible. */
  permission?: string;
}

export interface AdminMenuGroup {
  path: string;
  label: string;
  permission?: string;
  children: AdminMenuLeaf[];
}

export type AdminMenuEntry = AdminMenuLeaf | AdminMenuGroup;

const ENTRIES: readonly AdminMenuEntry[] = [
  { path: '/', label: '工作台', permission: 'dashboard.view' },
  { path: '/categories', label: '分类管理', permission: 'category.manage' },
  { path: '/courses', label: '课程管理', permission: 'course.view' },
  { path: '/qa', label: '问答管理', permission: 'qa.view' },
  { path: '/reviews', label: '评价管理', permission: 'review.view' },
  { path: '/maps', label: '学习地图', permission: 'map.view' },
  { path: '/orders', label: '订单管理', permission: 'order.view' },
  { path: '/learners', label: '学员账号', permission: 'learner.view' },
  { path: '/notifications', label: '通知管理', permission: 'notification.manage' },
  { path: '/scheduled-tasks', label: '自动任务', permission: 'scheduled_task.manage' },
  { path: '/site/profile', label: '站点资料', permission: 'site.manage' },
  { path: '/site/audit', label: '审计日志', permission: 'audit.view' },
  {
    path: '/org',
    label: '组织管理',
    permission: 'org.department',
    children: [
      { path: '/org/departments', label: '部门管理', permission: 'org.department' },
      { path: '/org/posts', label: '岗位管理', permission: 'org.post' },
      { path: '/org/roles', label: '角色管理', permission: 'org.role' },
      { path: '/org/staff', label: '员工管理', permission: 'org.staff' },
    ],
  },
] as const;

function isGroup(entry: AdminMenuEntry): entry is AdminMenuGroup {
  return Array.isArray((entry as AdminMenuGroup).children);
}

function visible(codes: ReadonlySet<string>, code: string | undefined): boolean {
  if (code === undefined) return true;
  return codes.has('*') || codes.has(code);
}

export function filterMenu(permissionCodes: readonly string[]): AdminMenuEntry[] {
  const codes = new Set(permissionCodes);
  const out: AdminMenuEntry[] = [];
  for (const entry of ENTRIES) {
    if (isGroup(entry)) {
      // Hide the group itself unless the caller can see at least one child.
      // The group label is redundant when empty — better to omit it.
      const children = entry.children.filter((c) => visible(codes, c.permission));
      if (children.length === 0) continue;
      // Keep the group's own `permission` for callers that want a single
      // gate; not used by AdminLayout.vue today but cheap to expose.
      out.push({ ...entry, children });
      continue;
    }
    if (visible(codes, entry.permission)) {
      out.push(entry);
    }
  }
  return out;
}

/**
 * Sidebar entries a given permission set can see. Convenience wrapper for
 * the `<el-menu>` loop in AdminLayout.vue so the template stays readable.
 */
export function visibleEntries(permissionCodes: readonly string[]): AdminMenuEntry[] {
  return filterMenu(permissionCodes);
}

/** First stable module entry the caller can actually open after login. */
export function firstVisiblePath(permissionCodes: readonly string[]): string | null {
  const first = filterMenu(permissionCodes)[0];
  if (first === undefined) return null;
  if (isGroup(first)) return first.children[0]?.path ?? null;
  return first.path;
}
