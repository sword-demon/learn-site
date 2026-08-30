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
});
