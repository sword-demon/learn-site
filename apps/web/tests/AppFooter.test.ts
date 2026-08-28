// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
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

function mountApp() {
  return mount(App, {
    global: {
      plugins: [createPinia()],
      stubs: {
        RouterLink: RouterLinkStub,
        RouterView: { template: '<main>当前页面</main>' },
      },
    },
  });
}

describe('App footer', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchHome.mockResolvedValue(homePayload);
  });

  it('renders the global footer with real navigation and configured contact email', async () => {
    const wrapper = mountApp();
    await flushPromises();

    const footer = wrapper.get('footer[aria-label="站点页脚"]');
    expect(wrapper.text()).toContain('当前页面');
    expect(footer.text()).toContain('林间课室');
    expect(footer.get('nav[aria-label="页脚导航"] a[href="/"]').text()).toContain('课程分类');
    expect(footer.get('a[href="/maps"]').text()).toContain('学习地图');
    expect(footer.get('a[href="mailto:courses@example.test"]').text()).toBe(
      'courses@example.test',
    );
  });

  it('keeps brand and navigation available when site metadata cannot be loaded', async () => {
    learnerApi.fetchHome.mockRejectedValueOnce(new Error('network down'));

    const wrapper = mountApp();
    await flushPromises();

    const footer = wrapper.get('footer[aria-label="站点页脚"]');
    expect(footer.text()).toContain('林间课室');
    expect(footer.get('a[href="/maps"]').text()).toContain('学习地图');
    expect(footer.find('a[href^="mailto:"]').exists()).toBe(false);
  });
});
