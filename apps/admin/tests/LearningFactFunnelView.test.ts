// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import type { LearningFactFunnelDTO } from '@learn-site/contracts';

const api = vi.hoisted(() => ({
  fetchLearningFactFunnel: vi.fn(),
}));

vi.mock('@/api/learningFactFunnel', () => api);

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { id: '12' }, path: '/courses/12/learning-funnel' }),
  useRouter: () => ({ push: vi.fn() }),
}));

import LearningFactFunnelView from '@/views/catalog/LearningFactFunnelView.vue';

function fixture(overrides: Partial<LearningFactFunnelDTO> = {}): LearningFactFunnelDTO {
  return {
    course_id: 12,
    window_days: 30,
    source: 'all',
    generated_at: '2026-09-11T12:00:00+08:00',
    disclaimer: '本报告展示事实转化，不表示增量效果，也不把订单成功或券已使用当成学习完成。',
    no_effective_lesson: false,
    stages: [
      { id: 'entitled', label: '访问权生效', count: 10, of_cohort_rate: 1, of_previous_rate: 1 },
      {
        id: 'first_opened',
        label: '首次打开课节',
        count: 6,
        of_cohort_rate: 0.6,
        of_previous_rate: 0.6,
      },
      {
        id: 'valid_progress',
        label: '产生有效进度',
        count: 4,
        of_cohort_rate: 0.4,
        of_previous_rate: 0.67,
      },
      { id: 'completed', label: '完成课程', count: 2, of_cohort_rate: 0.2, of_previous_rate: 0.5 },
    ],
    pending: {
      in_window: 3,
      in_window_label: '窗口进行中、尚未开始',
      window_elapsed: 1,
      window_elapsed_label: '窗口内未转化',
    },
    trial: {
      learners: 2,
      note: '未取得课程访问权时打开试看课节的登录学员，不含访客曝光',
    },
    orders: {
      succeeded: 5,
      buckets: [{ status: 'succeeded', count: 5 }],
      note: '订单成功不是完成课程',
    },
    publish_reach: {
      dispatch_count: 1,
      recipient_count: 8,
      entitled_count: 10,
      note: '发布触达与已有访问权人数分开，触达不是首次打开课节',
    },
    ...overrides,
  };
}

describe('LearningFactFunnelView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    api.fetchLearningFactFunnel.mockResolvedValue(fixture());
  });

  it('renders four stages and disclaimer without lift copy', async () => {
    const wrapper = mount(LearningFactFunnelView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('访问权生效');
    expect(wrapper.text()).toContain('10');
    expect(wrapper.text()).toContain('6');
    expect(wrapper.text()).toContain('本报告展示事实转化');
    expect(wrapper.text()).not.toContain('提升了完成率');
    expect(wrapper.text()).not.toContain('带来了学习效果');
    expect(wrapper.text()).not.toContain('付费带来完成');
    expect(wrapper.text()).not.toContain('发放提升学习');
    wrapper.unmount();
  });

  it('shows empty state when entitled is zero', async () => {
    api.fetchLearningFactFunnel.mockResolvedValue(
      fixture({
        stages: [
          {
            id: 'entitled',
            label: '访问权生效',
            count: 0,
            of_cohort_rate: null,
            of_previous_rate: null,
          },
          {
            id: 'first_opened',
            label: '首次打开课节',
            count: 0,
            of_cohort_rate: null,
            of_previous_rate: null,
          },
          {
            id: 'valid_progress',
            label: '产生有效进度',
            count: 0,
            of_cohort_rate: null,
            of_previous_rate: null,
          },
          {
            id: 'completed',
            label: '完成课程',
            count: 0,
            of_cohort_rate: null,
            of_previous_rate: null,
          },
        ],
      }),
    );
    const wrapper = mount(LearningFactFunnelView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('窗口内没有课程访问权生效');
    wrapper.unmount();
  });

  it('shows pending labels, contrast notes, generated_at and not-realtime copy', async () => {
    const wrapper = mount(LearningFactFunnelView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('窗口进行中、尚未开始');
    expect(wrapper.text()).toContain('窗口内未转化');
    expect(wrapper.text()).not.toContain('流失');
    expect(wrapper.text()).not.toContain('弃学');
    expect(wrapper.text()).toContain('订单成功不是完成课程');
    expect(wrapper.text()).toContain('支付成功');
    expect(wrapper.text()).toContain('2026-09-11T12:00:00+08:00');
    expect(wrapper.text()).toContain('非实时');
    expect(wrapper.text()).toContain('完成课程');
    expect(wrapper.text()).toContain('访问权生效时刻');
    expect(wrapper.text()).toContain('生效时刻加');
    expect(wrapper.text()).toContain('打开后产生完成课节才计有效进度');
    wrapper.unmount();
  });

  it('offers retry when load fails', async () => {
    api.fetchLearningFactFunnel
      .mockRejectedValueOnce(new Error('load_failed'))
      .mockResolvedValueOnce(fixture());
    const wrapper = mount(LearningFactFunnelView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('load_failed');
    await wrapper.get('[data-action="retry-funnel"]').trigger('click');
    await flushPromises();
    expect(api.fetchLearningFactFunnel).toHaveBeenCalledTimes(2);
    expect(wrapper.text()).toContain('访问权生效');
    wrapper.unmount();
  });
});
