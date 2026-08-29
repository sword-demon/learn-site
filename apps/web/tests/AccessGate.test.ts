// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({ startCourse: vi.fn() }));
const authApi = vi.hoisted(() => ({ hasTokens: vi.fn() }));
const routerApi = vi.hoisted(() => ({ push: vi.fn() }));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/http', () => authApi);
vi.mock('vue-router', () => ({
  useRoute: () => ({ fullPath: '/courses/7' }),
  useRouter: () => routerApi,
}));

import AccessGate from '@/views/catalog/AccessGate.vue';

describe('AccessGate', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    authApi.hasTokens.mockReturnValue(true);
    learnerApi.startCourse.mockResolvedValue({
      course_id: 7,
      entitled: true,
      source: 'free',
      price_mode: 'free',
      first_lesson: null,
    });
  });

  it('explains revoked access and uses the existing start endpoint to rejoin', async () => {
    const wrapper = mount(AccessGate, {
      props: {
        locked: true,
        viewerAuthorized: false,
        priceMode: 'free',
        courseId: 7,
        lessonId: 12,
        lessonTitle: '第一课',
        canRejoin: true,
        revokedReason: '误加入课程',
      },
    });

    expect(wrapper.get('.lock-trigger').text()).toContain('再次加入后学习');
    expect(wrapper.text()).toContain('访问权已被撤销');
    expect(wrapper.text()).toContain('误加入课程');

    await wrapper.get('.gate-actions .btn-primary').trigger('click');
    await flushPromises();

    expect(learnerApi.startCourse).toHaveBeenCalledWith(7);
    expect(routerApi.push).toHaveBeenCalledWith('/learn/7/12');
  });
});
