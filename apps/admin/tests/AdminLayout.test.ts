// @vitest-environment happy-dom

import { mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import ElementPlus from 'element-plus';
import { describe, expect, it, vi } from 'vitest';

const authApi = vi.hoisted(() => ({
  clearTokens: vi.fn(),
  permissionCodes: vi.fn(() => ['*']),
  staffAccount: vi.fn(() => 'admin'),
}));

const routerApi = vi.hoisted(() => ({
  route: { path: '/', fullPath: '/', meta: { title: '工作台', affix: true }, matched: [{ path: '/' }, { path: '' }] },
  push: vi.fn(),
}));

vi.mock('@/api/http', () => ({
  clearTokens: authApi.clearTokens,
  http: { post: vi.fn() },
  permissionCodes: authApi.permissionCodes,
  staffAccount: authApi.staffAccount,
}));

vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}));

import AdminLayout from '@/layouts/AdminLayout.vue';
import { visibleEntries } from '@/layouts/AdminMenu';
import { useTabsStore } from '@/stores/tabs';

describe('AdminLayout', () => {
  it('renders an SVG icon for every visible menu item', () => {
    setActivePinia(createPinia());
    const wrapper = mount(AdminLayout, {
      global: {
        plugins: [ElementPlus],
        stubs: {
          'router-view': true,
          AdminTabBar: true,
          AdminBreadcrumb: true,
        },
      },
    });

    const menuItems = wrapper.findAll('.el-menu-item');
    const submenuTitles = wrapper.findAll('.el-sub-menu__title');
    const entries = visibleEntries(['*']);
    const expectedMenuItems = entries.reduce(
      (count, entry) => count + ('children' in entry ? entry.children.length : 1),
      0,
    );
    const expectedSubmenus = entries.filter((entry) => 'children' in entry).length;

    expect(menuItems).toHaveLength(expectedMenuItems);
    expect(submenuTitles).toHaveLength(expectedSubmenus);
    expect(menuItems.every((item) => item.find('svg').exists())).toBe(true);
    expect(submenuTitles.every((title) => title.find('svg').exists())).toBe(true);
  });

  it('includes tab bar and breadcrumb chrome', () => {
    setActivePinia(createPinia());
    const wrapper = mount(AdminLayout, {
      global: {
        plugins: [ElementPlus],
        stubs: { 'router-view': true },
      },
    });

    expect(wrapper.find('.admin-tab-bar').exists()).toBe(true);
    expect(wrapper.find('.admin-breadcrumb').exists()).toBe(true);
  });

  it('initializes affixed dashboard tab when layout mounts', () => {
    setActivePinia(createPinia());
    mount(AdminLayout, {
      global: {
        plugins: [ElementPlus],
        stubs: {
          AdminTabBar: true,
          AdminBreadcrumb: true,
          'router-view': true,
        },
      },
    });

    const store = useTabsStore();
    expect(store.opened).toHaveLength(1);
    expect(store.opened[0]?.title).toBe('工作台');
    expect(store.opened[0]?.affix).toBe(true);
  });
});
