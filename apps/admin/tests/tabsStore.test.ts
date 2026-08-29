// @vitest-environment happy-dom

import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';
import { DASHBOARD_TAB_KEY, useTabsStore } from '@/stores/tabs';

function routeOf(fullPath: string, meta: Record<string, unknown> = { title: '测试页' }) {
  return {
    fullPath,
    path: fullPath.split('?')[0] ?? fullPath,
    name: 'test',
    meta,
    matched: [{ path: '/' }, { path: fullPath }],
  } as never;
}

describe('tabs store', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('starts with affixed dashboard tab only', () => {
    const store = useTabsStore();
    expect(store.opened).toHaveLength(1);
    expect(store.opened[0]?.affix).toBe(true);
    expect(store.opened[0]?.closable).toBe(false);
    expect(store.activeKey).toBe(DASHBOARD_TAB_KEY);
  });

  it('opens and reuses tabs by fullPath', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    expect(store.opened).toHaveLength(2);
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    expect(store.opened).toHaveLength(2);
    expect(store.activeKey).toBe('/courses');
  });

  it('closes active tab and activates a neighbor', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    store.openTab(routeOf('/orders', { title: '订单管理' }));
    const nextKey = store.closeTab('/orders');
    expect(nextKey).toBe('/courses');
    expect(store.opened.some((tab) => tab.key === '/orders')).toBe(false);
  });

  it('cannot close affixed dashboard tab', () => {
    const store = useTabsStore();
    expect(store.closeTab(DASHBOARD_TAB_KEY)).toBeUndefined();
    expect(store.opened).toHaveLength(1);
  });

  it('closeOthers keeps affix and active tab', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    store.openTab(routeOf('/orders', { title: '订单管理' }));
    store.closeOthers('/courses');
    expect(store.opened.map((tab) => tab.key)).toEqual([DASHBOARD_TAB_KEY, '/courses']);
  });

  it('closeAll returns dashboard and keeps only affix tabs', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    const next = store.closeAll();
    expect(next).toBe(DASHBOARD_TAB_KEY);
    expect(store.opened).toHaveLength(1);
    expect(store.activeKey).toBe(DASHBOARD_TAB_KEY);
  });

  it('reset restores initial dashboard-only state', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/courses', { title: '课程管理' }));
    store.reset();
    expect(store.opened).toHaveLength(1);
    expect(store.activeKey).toBe(DASHBOARD_TAB_KEY);
  });

  it('skips hideInTabs routes', () => {
    const store = useTabsStore();
    store.openTab(routeOf('/forbidden', { title: '无权访问', hideInTabs: true }));
    expect(store.opened).toHaveLength(1);
  });
});
