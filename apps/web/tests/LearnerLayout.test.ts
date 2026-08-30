// @vitest-environment happy-dom

import { readFileSync } from 'node:fs';
import path from 'node:path';
import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { LearnerProfileDTO } from '@learn-site/contracts';
import { useLoginFamilyStore } from '@/api/login';
import { useLearnerProfileStore } from '@/stores/learnerProfile';
import { useNotificationStore } from '@/stores/notifications';

const learnerApi = vi.hoisted(() => ({
  fetchLearnerProfile: vi.fn(),
}));
const checkinsApi = vi.hoisted(() => ({
  fetchTodayCheckinStatus: vi.fn(),
}));
const notificationsApi = vi.hoisted(() => ({
  fetchUnreadCount: vi.fn(),
}));
const pushApi = vi.hoisted(() => ({
  createPushConnection: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/api/notifications', () => notificationsApi);
vi.mock('@/utils/push', () => pushApi);
vi.mock('@/components/DailyCheckinDialog.vue', () => ({
  default: {
    name: 'DailyCheckinDialog',
    props: ['modelValue'],
    template: '<div data-testid="checkin-dialog" />',
  },
}));

import LearnerLayout from '@/layouts/LearnerLayout.vue';

const profile: LearnerProfileDTO = {
  account_id: 7,
  phone: '13800138000',
  nickname: '无解的游戏',
  avatar_url: null,
  show_on_course: false,
  status: 'active',
  created_at: '2026-08-30 10:00:00',
};

const navRoutes = [
  { path: '/', component: { template: '<main>首页</main>' } },
  { path: '/maps', component: { template: '<main>地图</main>' } },
  { path: '/me/learning', component: { template: '<main>学习</main>' } },
  { path: '/me/favorites', component: { template: '<main>收藏</main>' } },
  { path: '/me/orders', component: { template: '<main>订单</main>' } },
  { path: '/me/messages', component: { template: '<main>消息</main>' } },
  { path: '/me/checkins', component: { template: '<main>签到</main>' } },
  { path: '/me/account', component: { template: '<main>账户</main>' } },
  { path: '/login', component: { template: '<main>登录</main>' } },
];

async function mountLayout(loggedIn = false) {
  const pinia = createPinia();
  setActivePinia(pinia);
  if (loggedIn) {
    useLoginFamilyStore().signIn({
      access_token: 'access-token',
      refresh_token: 'refresh-token',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });
  }
  const router = createRouter({
    history: createMemoryHistory(),
    routes: navRoutes,
  });
  await router.push('/');
  await router.isReady();
  const wrapper = mount(LearnerLayout, {
    global: {
      plugins: [pinia, router],
    },
  });
  await flushPromises();
  return wrapper;
}

describe('LearnerLayout masthead', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    localStorage.clear();
    sessionStorage.clear();
    learnerApi.fetchLearnerProfile.mockResolvedValue(profile);
    checkinsApi.fetchTodayCheckinStatus.mockResolvedValue({
      server_date: '2026-08-30',
      checked_in: true,
      record: null,
    });
    notificationsApi.fetchUnreadCount.mockResolvedValue({ count: 3 });
    pushApi.createPushConnection.mockResolvedValue({
      subscribe: () => ({ on: vi.fn() }),
      disconnect: vi.fn(),
    });
  });

  it('keeps brand tools and nav on separate rows', async () => {
    const wrapper = await mountLayout();
    const bar = wrapper.get('.masthead-bar');
    const nav = wrapper.get('#learner-navigation');

    expect(bar.find('.brand').exists()).toBe(true);
    expect(bar.find('.masthead-tools').exists()).toBe(true);
    expect(bar.find('#learner-navigation').exists()).toBe(false);
    expect(nav.element.parentElement?.classList.contains('masthead-inner')).toBe(true);
    expect(nav.element.previousElementSibling?.classList.contains('masthead-bar')).toBe(true);
  });

  it('shows compact public links before login', async () => {
    const wrapper = await mountLayout();
    const hrefs = wrapper.findAll('#learner-navigation a').map((link) => link.attributes('href'));
    expect(hrefs).toEqual(['/', '/maps']);
    expect(wrapper.text()).toContain('登录 / 注册');
    expect(wrapper.text()).not.toContain('每日签到');
  });

  it('shows logged-in links without wrapping the nickname', async () => {
    const wrapper = await mountLayout(true);
    useLearnerProfileStore().setProfile(profile);
    useNotificationStore().unreadCount = 3;
    await flushPromises();

    const hrefs = wrapper.findAll('#learner-navigation a').map((link) => link.attributes('href'));
    expect(hrefs).toEqual([
      '/',
      '/maps',
      '/me/learning',
      '/me/favorites',
      '/me/orders',
      '/me/messages',
      '/me/checkins',
    ]);
    expect(wrapper.get('.chip-user-name').text()).toBe('无解的游戏');
    expect(wrapper.get('.nav-badge').text()).toBe('3');
    expect(wrapper.get('#learner-navigation').attributes('aria-label')).toBe('主导航');
  });
});

describe('learner masthead CSS', () => {
  it('forces nav labels onto a single line in a dedicated row', () => {
    const css = readFileSync(path.resolve(process.cwd(), 'src/style.css'), 'utf8');
    const navLink = css.match(/\.mainnav a \{[\s\S]*?\n\}/)?.[0] ?? '';
    const inner = css.match(/\.masthead-inner \{[\s\S]*?\n\}/)?.[0] ?? '';

    expect(navLink).toContain('white-space: nowrap');
    expect(navLink).toContain('flex-shrink: 0');
    expect(inner).not.toContain('flex-wrap: wrap');
    expect(css).toContain('.masthead-bar {');
    expect(css).toContain('.masthead-inner:has(.masthead-bar)');
  });
});
