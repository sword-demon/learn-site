// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { HomePayload } from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchHome: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);

import AboutView from '@/views/about/AboutView.vue';

const homePayload: HomePayload = {
  categories: [],
  recent_courses: [],
  banners: [],
  recommended_maps: [],
  site_intro: {
    title: '把每一次学习，收进自己的课程档案',
    subtitle: '从一门课开始。',
    body_html: '<p>拾阶学社致力于结构化在线学习。</p>',
    contact_email: 'courses@example.test',
    updated_at: '2026-08-28 10:00:00',
  },
};

describe('AboutView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.clearAllMocks();
    learnerApi.fetchHome.mockResolvedValue(homePayload);
  });

  it('renders site intro from home payload', async () => {
    const wrapper = mount(AboutView);
    await flushPromises();

    expect(wrapper.get('[data-view="about"]').attributes('data-view')).toBe('about');
    expect(wrapper.text()).toContain('把每一次学习，收进自己的课程档案');
    expect(wrapper.text()).toContain('从一门课开始。');
    expect(wrapper.text()).toContain('拾阶学社致力于结构化在线学习。');
    expect(wrapper.get('a[href="mailto:courses@example.test"]').text()).toBe('courses@example.test');
  });

  it('shows fallback copy when body_html is empty', async () => {
    learnerApi.fetchHome.mockResolvedValueOnce({
      ...homePayload,
      site_intro: {
        ...homePayload.site_intro,
        body_html: '',
      },
    });

    const wrapper = mount(AboutView);
    await flushPromises();

    expect(wrapper.text()).toContain('专注结构化在线学习');
    expect(wrapper.text()).toContain('courses@example.test');
  });
});
