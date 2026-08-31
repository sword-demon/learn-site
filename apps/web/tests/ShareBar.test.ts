// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const shareApi = vi.hoisted(() => ({
  createShareLink: vi.fn(),
  createSharePoster: vi.fn(),
}));
const authApi = vi.hoisted(() => ({ hasTokens: vi.fn() }));
const learnerApi = vi.hoisted(() => ({ addFavorite: vi.fn() }));
const routerApi = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock('@/api/share', () => shareApi);
vi.mock('@/api/http', () => authApi);
vi.mock('@/api/learner', () => learnerApi);
vi.mock('vue-router', () => ({
  useRoute: () => ({ fullPath: '/courses/9' }),
  useRouter: () => routerApi,
}));

import ShareBar from '@/views/catalog/ShareBar.vue';

describe('ShareBar', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasTokens.mockReturnValue(true);
    Object.defineProperty(navigator, 'clipboard', {
      configurable: true,
      value: { writeText: vi.fn().mockResolvedValue(undefined) },
    });
    shareApi.createShareLink.mockResolvedValue({ course_id: 9, share_url: '/courses/9' });
    learnerApi.addFavorite.mockResolvedValue({ course_id: 9, favorited: true });
    shareApi.createSharePoster.mockResolvedValue({
      poster_id: 4,
      token: 'poster-token',
      share_url: '/courses/9',
      render_status: 'ready',
      snapshot: {
        cover_url: null,
        title: 'Vue 组件设计',
        teacher_name: '林老师',
        price_label: '免费',
      },
    });
  });

  it('copies the stable public course link independently of poster generation', async () => {
    const wrapper = mount(ShareBar, {
      props: { courseId: 9, courseTitle: 'Vue 组件设计' },
      global: { stubs: { SharePosterDialog: true } },
    });

    await wrapper.get('[data-action="copy-link"]').trigger('click');
    await flushPromises();

    expect(shareApi.createShareLink).toHaveBeenCalledWith(9);
    expect(navigator.clipboard.writeText).toHaveBeenCalledWith('http://localhost:3000/courses/9');
    expect(wrapper.text()).toContain('已复制');
  });

  it('keeps the stable link available when poster generation fails', async () => {
    shareApi.createSharePoster.mockRejectedValue(new Error('POSTER_RENDER_FAILED'));
    const wrapper = mount(ShareBar, {
      props: { courseId: 9, courseTitle: 'Vue 组件设计' },
      global: { stubs: { SharePosterDialog: true } },
    });

    await wrapper.get('[data-action="generate-poster"]').trigger('click');
    await flushPromises();

    expect(shareApi.createShareLink).toHaveBeenCalledWith(9);
    expect(shareApi.createSharePoster).toHaveBeenCalledWith(9);
    expect(wrapper.text()).toContain('海报生成失败');
    expect(wrapper.get('a').attributes('href')).toBe('http://localhost:3000/courses/9');
  });

  it('offers an idempotent favorite action from the course detail surface', async () => {
    const wrapper = mount(ShareBar, {
      props: { courseId: 9, courseTitle: 'Vue 组件设计' },
      global: { stubs: { SharePosterDialog: true } },
    });

    await wrapper.get('[data-action="favorite"]').trigger('click');
    await flushPromises();

    expect(learnerApi.addFavorite).toHaveBeenCalledWith(9);
    expect(wrapper.text()).toContain('已收藏');
  });

  it('redirects a visitor to learner login before favoriting a course', async () => {
    authApi.hasTokens.mockReturnValue(false);
    const wrapper = mount(ShareBar, {
      props: { courseId: 9, courseTitle: 'Vue 组件设计' },
      global: { stubs: { SharePosterDialog: true } },
    });

    await wrapper.get('[data-action="favorite"]').trigger('click');
    await flushPromises();

    expect(routerApi.push).toHaveBeenCalledWith('/login?redirect=%2Fcourses%2F9');
    expect(learnerApi.addFavorite).not.toHaveBeenCalled();
  });
});
