// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({ fetchMyLearning: vi.fn(), startCourse: vi.fn() }));
const routerApi = vi.hoisted(() => ({ push: vi.fn() }));
vi.mock('@/api/learner', () => learnerApi);
vi.mock('vue-router', () => ({ useRouter: () => routerApi }));

import MyLearningView from '@/views/me/MyLearningView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

describe('MyLearningView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnerApi.fetchMyLearning.mockResolvedValue({
      items: [
        {
          course_id: 7,
          progress_percent: 40,
          last_lesson_id: 12,
          last_position: 120,
          completed_at: null,
          updated_at: '2026-08-28 10:00:00',
          entitlement_status: 'active',
          entitlement_source: 'free',
          revoked_at: null,
          revoked_reason: null,
          can_rejoin: false,
          course: {
            id: 7,
            title: 'Webman 实战',
            cover_url: null,
            teacher_name: '林老师',
            status: 'published',
            price_mode: 'free',
          },
        },
      ],
    });
  });

  it('renders persisted progress and a resume link', async () => {
    const wrapper = mount(MyLearningView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(learnerApi.fetchMyLearning).toHaveBeenCalledOnce();
    expect(wrapper.text()).toContain('Webman 实战');
    expect(wrapper.text()).toContain('40%');
    expect(wrapper.get('a[href="/learn/7/12"]').text()).toContain('继续学习');
  });

  it('shows a useful empty state when there is no enrollment', async () => {
    learnerApi.fetchMyLearning.mockResolvedValueOnce({ items: [] });
    const wrapper = mount(MyLearningView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.text()).toContain('还没有学习记录');
    expect(wrapper.find('.course-row').exists()).toBe(false);
  });

  it('shows the revoke reason and rejoins without losing the resume position', async () => {
    learnerApi.fetchMyLearning.mockResolvedValueOnce({
      items: [
        {
          course_id: 7,
          progress_percent: 40,
          last_lesson_id: 12,
          last_position: 120,
          completed_at: null,
          updated_at: '2026-08-28 11:00:00',
          entitlement_status: 'revoked',
          entitlement_source: 'free',
          revoked_at: '2026-08-28 11:00:00',
          revoked_reason: '误加入测试课程',
          can_rejoin: true,
          course: {
            id: 7,
            title: 'Webman 实战',
            cover_url: null,
            teacher_name: '林老师',
            status: 'published',
            price_mode: 'free',
          },
        },
      ],
    });
    learnerApi.startCourse.mockResolvedValueOnce({
      course_id: 7,
      entitled: true,
      source: 'free',
      price_mode: 'free',
      first_lesson: null,
    });

    const wrapper = mount(MyLearningView, { global: { stubs: { RouterLink: RouterLinkStub } } });
    await flushPromises();

    expect(wrapper.text()).toContain('访问已撤销');
    expect(wrapper.text()).toContain('误加入测试课程');
    expect(wrapper.find('a[href="/learn/7/12"]').exists()).toBe(false);

    await wrapper.get('button[data-action="rejoin"]').trigger('click');
    await flushPromises();

    expect(learnerApi.startCourse).toHaveBeenCalledWith(7);
    expect(routerApi.push).toHaveBeenCalledWith('/learn/7/12');
  });
});
