// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia } from 'pinia';
import { createMemoryHistory, createRouter } from 'vue-router';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchCategoryCourses: vi.fn(),
  fetchHome: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import HomeView from '@/views/home/HomeView.vue';

const category = { id: 3, name: '前端开发', children: [] };
const course = {
  id: 9,
  category_id: 3,
  title: 'Vue 组件设计',
  cover_url: null,
  teacher_name: '林老师',
  summary: '从状态到组件边界',
  price_mode: 'free',
  list_price: 0,
  sale_price: 0,
  sale_start_at: null,
  sale_end_at: null,
  preview_available: true,
  learner_count: 12,
  updated_at: '2026-08-30 10:00:00',
};

describe('HomeView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchHome.mockResolvedValue({
      categories: [category],
      recent_courses: [course],
      banners: [],
      site_intro: {
        title: '拾阶学社',
        subtitle: '日进一阶',
        body_html: '',
        contact_email: '',
        updated_at: '2026-08-30 10:00:00',
      },
    });
    learnerApi.fetchCategoryCourses.mockResolvedValue({
      category: { id: 3, name: '前端开发', ancestors: [] },
      list: { items: [course], total: 1, page: 1, limit: 100 },
    });
  });

  it('uses el-tree and reloads courses when a category node is selected', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: HomeView }],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [createPinia(), router] } });
    await flushPromises();

    expect(wrapper.get('[data-action="all-categories"]').text()).toContain('全部分类');
    const tree = wrapper.findComponent({ name: 'ElTree' });
    expect(tree.exists()).toBe(true);
    expect(tree.props('data')).toEqual([category]);

    await wrapper.get('.el-tree-node__content').trigger('click');
    await flushPromises();

    expect(router.currentRoute.value.query).toEqual({ cat: '3' });
    expect(learnerApi.fetchCategoryCourses).toHaveBeenCalledWith(3, 1, 100);
    expect(learnerApi.fetchCategoryCourses).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).toContain('前端开发');
  });

  it('mounts the home banner carousel from the home payload', async () => {
    learnerApi.fetchHome.mockResolvedValueOnce({
      categories: [],
      recent_courses: [],
      banners: [
        {
          id: 7,
          image_url: '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
          link_url: null,
          sort_order: 0,
        },
      ],
      site_intro: {
        title: '拾阶学社',
        subtitle: '日进一阶',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
    });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: HomeView }],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [createPinia(), router] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="home-banner-carousel"]').exists()).toBe(true);
    expect(wrapper.find('.banner-image').attributes('src')).toContain('/api/media/banners/');
    expect(wrapper.text()).toContain('拾阶学社');
  });

  it('hides the hero carousel when home payload has no banners', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: HomeView }],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [createPinia(), router] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="home-banner-carousel"]').exists()).toBe(false);
    expect(wrapper.find('.home-hero-fallback').exists()).toBe(false);
  });

  it('renders the recommended learning maps rail when payload has maps', async () => {
    learnerApi.fetchHome.mockResolvedValueOnce({
      categories: [category],
      recent_courses: [course],
      banners: [],
      recommended_maps: [
        {
          id: 11,
          department_id: 1,
          title: '诸子百家',
          summary: '了解春秋战国的思想流派',
          cover_url: null,
          objective: null,
          audience: null,
          status: 'published' as const,
          created_at: '2026-08-30 10:00:00',
          updated_at: '2026-08-31 10:00:00',
          enrollment: null,
        },
      ],
      site_intro: {
        title: '拾阶学社',
        subtitle: '日进一阶',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
    });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: HomeView },
        { path: '/maps/:id', component: { template: '<div />' } },
        { path: '/maps', component: { template: '<div />' } },
      ],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [createPinia(), router] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="recommended-map-rail"]').exists()).toBe(true);
    expect(wrapper.text()).toContain('推荐学习地图');
    expect(wrapper.text()).toContain('诸子百家');
    expect(wrapper.text()).toContain('了解春秋战国的思想流派');
  });

  it('hides the recommended learning maps rail when payload has none', async () => {
    learnerApi.fetchHome.mockResolvedValueOnce({
      categories: [],
      recent_courses: [],
      banners: [],
      recommended_maps: [],
      site_intro: {
        title: '拾阶学社',
        subtitle: '日进一阶',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
    });
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [{ path: '/', component: HomeView }],
    });
    await router.push('/');
    await router.isReady();

    const wrapper = mount(HomeView, { global: { plugins: [createPinia(), router] } });
    await flushPromises();

    expect(wrapper.find('[data-testid="recommended-map-rail"]').exists()).toBe(false);
  });
});
