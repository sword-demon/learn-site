// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { createRouter, createWebHistory } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { HomePayload } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchHome: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import App from '@/App.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const homePayload: HomePayload = {
  categories: [],
  recent_courses: [],
  site_intro: {
    title: '把每一次学习，收进自己的课程档案',
    subtitle: '从一门课开始。',
    body_html: '',
    contact_email: 'courses@example.test',
    updated_at: '2026-08-28 10:00:00',
  },
};

function mountApp(route = '/') {
  const router = createRouter({
    history: createWebHistory(),
    routes: [
      { path: '/', component: { template: '<main>当前页面</main>' } },
      { path: '/login', meta: { hideFooter: true }, component: { template: '<main>登录</main>' } },
    ],
  });

  return router.push(route).then(() =>
    mount(App, {
      global: {
        plugins: [createPinia(), router],
        stubs: {
          RouterLink: RouterLinkStub,
          RouterView: { template: '<main>当前页面</main>' },
        },
      },
    }),
  );
}

describe('App footer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchHome.mockResolvedValue(homePayload);
  });

  it('hides the global footer on auth routes', async () => {
    const router = createRouter({
      history: createWebHistory(),
      routes: [
        { path: '/login', meta: { hideFooter: true }, component: { template: '<main>登录</main>' } },
        { path: '/', component: { template: '<main>首页</main>' } },
      ],
    });
    await router.push('/login');
    await router.isReady();

    const wrapper = mount(App, {
      global: {
        plugins: [createPinia(), router],
        stubs: {
          RouterView: { template: '<main>登录</main>' },
        },
      },
    });
    await flushPromises();

    expect(wrapper.find('footer[aria-label="站点页脚"]').exists()).toBe(false);
  });

  it('renders the global footer with real navigation and configured contact email', async () => {
    const wrapper = await mountApp();
    await flushPromises();

    const footer = wrapper.get('footer[aria-label="站点页脚"]');
    expect(wrapper.text()).toContain('当前页面');
    expect(footer.text()).toContain('拾阶学社');
    expect(footer.get('nav[aria-label="页脚导航"] a[href="/"]').text()).toContain('首页');
    expect(footer.get('a[href="/maps"]').text()).toContain('学习地图');
    expect(footer.get('a[href="mailto:courses@example.test"]').text()).toBe(
      'courses@example.test',
    );
  });

  it('keeps brand and navigation available when site metadata cannot be loaded', async () => {
    learnerApi.fetchHome.mockRejectedValueOnce(new Error('network down'));

    const wrapper = await mountApp();
    await flushPromises();

    const footer = wrapper.get('footer[aria-label="站点页脚"]');
    expect(footer.text()).toContain('拾阶学社');
    expect(footer.get('a[href="/maps"]').text()).toContain('学习地图');
    expect(footer.find('a[href^="mailto:"]').exists()).toBe(false);
  });
});
