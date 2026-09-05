// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { describe, expect, it, vi } from 'vitest';
import HomeView from '@/views/home/HomeView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

vi.mock('@/api/learner', () => ({
  fetchHome: vi.fn().mockResolvedValue({
    categories: [],
    recent_courses: [],
    banners: [],
    recommended_maps: [],
    site_intro: {
      title: '拾阶学社',
      subtitle: '',
      body_html: '',
      contact_email: '',
      updated_at: null,
    },
  }),
  fetchCategoryCourses: vi.fn(),
}));
vi.mock('@/api/login', () => ({
  useLoginFamilyStore: () => ({ loggedIn: true }),
}));

vi.mock('@/api/learningAction', () => ({
  fetchNextAction: vi.fn().mockResolvedValue({
    state: 'ready',
    action: {
      type: 'continue_lesson',
      priority: 3,
      rule_code: 'continue_authorized_lesson',
      reason_code: 'CONTINUE_LAST_LESSON',
      title: '继续学习：HTTP 请求生命周期',
      reason: '继续上次未完成的课节',
      target: { resource_type: 'lesson', resource_id: 42, path: '/learn/7/42' },
      availability: 'available',
      availability_reason: null,
      generated_at: '2026-09-04T10:00:00+08:00',
    },
    fallback: null,
    evaluated_at: '2026-09-04T10:00:00+08:00',
    degraded_dependencies: [],
  }),
}));
describe('HomeView learning action', () => {
  it('renders one server-owned action with its reason and target', async () => {
    const pinia = createPinia();
    setActivePinia(pinia);
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: HomeView }],
    });
    await router.push('/');
    await router.isReady();
    const wrapper = mount(HomeView, {
      global: { plugins: [pinia, router], stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect(wrapper.findAll('[data-testid="learning-action"]').length).toBe(1);
    expect(wrapper.text()).toContain('继续学习：HTTP 请求生命周期');
    expect(wrapper.text()).toContain('继续上次未完成的课节');
    expect(wrapper.find('a[href="/learn/7/42"]').exists()).toBe(true);
  });
});
