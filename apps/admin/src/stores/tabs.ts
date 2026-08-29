import { defineStore } from 'pinia';
import type { RouteLocationNormalized } from 'vue-router';
import { shouldTrackTab } from '@/router/tabSync';

export interface AdminTab {
  key: string;
  title: string;
  path: string;
  name?: string;
  affix: boolean;
  closable: boolean;
}

const FALLBACK_TITLE = '未命名页面';
export const DASHBOARD_TAB_KEY = '/';

function createDashboardTab(): AdminTab {
  return {
    key: DASHBOARD_TAB_KEY,
    title: '工作台',
    path: '/',
    name: 'dashboard',
    affix: true,
    closable: false,
  };
}

function titleFromRoute(route: RouteLocationNormalized): string {
  const title = route.meta.title;
  return typeof title === 'string' && title.length > 0 ? title : FALLBACK_TITLE;
}

function tabFromRoute(route: RouteLocationNormalized): AdminTab {
  const affix = route.meta.affix === true;
  const tab: AdminTab = {
    key: route.fullPath,
    title: titleFromRoute(route),
    path: route.path,
    affix,
    closable: !affix,
  };
  if (typeof route.name === 'string') {
    tab.name = route.name;
  }
  return tab;
}

export const useTabsStore = defineStore('admin-tabs', {
  state: () => ({
    opened: [createDashboardTab()] as AdminTab[],
    activeKey: DASHBOARD_TAB_KEY,
  }),

  actions: {
    openTab(route: RouteLocationNormalized): void {
      if (!shouldTrackTab(route)) return;

      const key = route.fullPath;
      const existing = this.opened.find((tab) => tab.key === key);
      if (existing) {
        existing.title = titleFromRoute(route);
        this.activeKey = key;
        return;
      }

      this.opened.push(tabFromRoute(route));
      this.activeKey = key;
    },

    activateTab(key: string): void {
      if (this.opened.some((tab) => tab.key === key)) {
        this.activeKey = key;
      }
    },

    /** Returns the next active tab key after close, for router navigation. */
    closeTab(key: string): string | undefined {
      const index = this.opened.findIndex((tab) => tab.key === key);
      if (index < 0) return undefined;

      const tab = this.opened[index];
      if (tab === undefined || !tab.closable) return undefined;

      this.opened.splice(index, 1);

      if (this.activeKey !== key) return undefined;

      const next = this.opened[index] ?? this.opened[index - 1] ?? this.opened[0];
      const nextKey = next?.key ?? DASHBOARD_TAB_KEY;
      this.activeKey = nextKey;
      return nextKey;
    },

    closeOthers(activeKey: string): void {
      this.opened = this.opened.filter((tab) => tab.affix || tab.key === activeKey);
      this.activeKey = activeKey;
    },

    closeAll(): string {
      this.opened = this.opened.filter((tab) => tab.affix);
      this.activeKey = DASHBOARD_TAB_KEY;
      return DASHBOARD_TAB_KEY;
    },

    updateTitle(key: string, title: string): void {
      const tab = this.opened.find((item) => item.key === key);
      if (tab && title.trim().length > 0) {
        tab.title = title.trim();
      }
    },

    syncFromRoute(route: RouteLocationNormalized): void {
      if (!shouldTrackTab(route)) return;
      this.openTab(route);
    },

    reset(): void {
      this.opened = [createDashboardTab()];
      this.activeKey = DASHBOARD_TAB_KEY;
    },
  },
});
