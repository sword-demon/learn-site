import { describe, expect, it } from 'vitest';
import { filterMenu, type AdminMenuGroup, type AdminMenuLeaf } from '@/layouts/AdminMenu';

// US13 / T059 — sidebar items the caller can see.
//
// The independent test (员工仅获得问答权限时,看不到课程维护和订单)
// requires that an account holding only `qa.view` sees neither the
// catalog sub-tree nor the org sub-tree. This file pins that contract so
// the menu helper can't silently regress.

function paths(entries: ReturnType<typeof filterMenu>): string[] {
  const out: string[] = [];
  for (const e of entries) {
    if ('children' in e && e.children) {
      out.push(e.path);
      for (const c of e.children) out.push(c.path);
    } else {
      out.push(e.path);
    }
  }
  return out;
}

describe('filterMenu', () => {
  it('returns no protected entries for a caller with no permissions', () => {
    const out = paths(filterMenu([]));
    expect(out).toEqual([]);
  });

  it('keeps every menu entry for super admin (codes include "*")', () => {
    const out = paths(filterMenu(['*']));
    // Every protected leaf must appear, plus the dashboard root and the
    // /org group container.
    expect(out).toContain('/');
    expect(out).toContain('/categories');
    expect(out).toContain('/courses');
    expect(out).toContain('/org');
    expect(out).toContain('/org/departments');
    expect(out).toContain('/org/posts');
    expect(out).toContain('/org/roles');
    expect(out).toContain('/org/staff');
  });

  it('hides catalog and org entries for a Q&A-only account (US13 independent test)', () => {
    const out = paths(filterMenu(['qa.view', 'qa.answer']));
    // The bug we are guarding against: a Q&A-only operator must not see
    // course maintenance or org administration in the sidebar.
    expect(out).not.toContain('/categories');
    expect(out).not.toContain('/courses');
    expect(out).not.toContain('/org');
    expect(out).not.toContain('/org/departments');
    expect(out).not.toContain('/org/posts');
    expect(out).not.toContain('/org/roles');
    expect(out).not.toContain('/org/staff');
    expect(out).not.toContain('/');
    expect(out).not.toContain('/orders');
    expect(out).not.toContain('/site/profile');
    expect(out).toContain('/qa');
  });

  it('hides the org group when none of its children are visible', () => {
    // A caller with only course.view: catalog is visible, org is not. The
    // group container itself must drop out so we do not show an empty
    // "组织管理" header.
    const out = filterMenu(['course.view']);
    const groups = out.filter(
      (e): e is AdminMenuGroup => 'children' in e && Array.isArray((e as AdminMenuGroup).children),
    );
    expect(groups).toEqual([]);
    const leaves = out.filter((e): e is AdminMenuLeaf => !('children' in e));
    expect(leaves.map((l) => l.path).sort()).toEqual(['/courses']);
  });

  it('keeps only the children the caller has permission for inside a group', () => {
    const out = filterMenu(['org.department', 'org.staff']);
    const org = out.find((e) => e.path === '/org') as AdminMenuGroup | undefined;
    expect(org).toBeDefined();
    const childPaths = org?.children.map((c) => c.path).sort() ?? [];
    expect(childPaths).toEqual(['/org/departments', '/org/staff']);
  });
});
