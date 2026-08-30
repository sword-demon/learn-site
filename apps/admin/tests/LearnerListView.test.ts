// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const learnersApi = vi.hoisted(() => ({
  listLearners: vi.fn(),
  kickLearner: vi.fn(),
  resetLearnerPassword: vi.fn(),
}));

const routerApi = vi.hoisted(() => ({
  push: vi.fn(),
}));

vi.mock('@/api/learners', () => learnersApi);
vi.mock('vue-router', async () => {
  const actual = await vi.importActual<typeof import('vue-router')>('vue-router');
  return {
    ...actual,
    useRouter: () => routerApi,
  };
});

import LearnerListView from '@/views/students/LearnerListView.vue';

describe('LearnerListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    learnersApi.listLearners.mockResolvedValue({
      items: [
        {
          account_id: 7,
          login: '13912345678',
          display_name: '小王',
          department_id: null,
          department_name: '',
          status: 'active',
          must_change_password: false,
          last_login_at: null,
          created_at: '2026-08-28 10:00:00',
          course_count: 3,
          completed_course_count: 1,
          successful_order_count: 2,
          total_paid_amount: 198,
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
  });

  function mountLearners() {
    return mount(LearnerListView, { global: { plugins: [installElementPlus] } });
  }

  it('renders learning and successful purchase summaries with Chinese status labels', async () => {
    const wrapper = mountLearners();
    await flushPromises();

    expect(wrapper.find('.filter-control').exists()).toBe(true);
    expect(wrapper.text()).toContain('3 门 / 完成 1 门');
    expect(wrapper.text()).toContain('2 单 / ¥198.00');
    expect(wrapper.text()).toContain('正常');
    expect(wrapper.text()).not.toContain('active');
  });

  it('navigates to learner progress and learning record pages', async () => {
    const wrapper = mountLearners();
    await flushPromises();

    const buttons = wrapper.findAll('.actions .el-button');
    const progressButton = buttons.find((button) => button.text().includes('学习进度'));
    const recordsButton = buttons.find((button) => button.text().includes('学习记录'));
    expect(progressButton).toBeDefined();
    expect(recordsButton).toBeDefined();

    await progressButton?.trigger('click');
    expect(routerApi.push).toHaveBeenCalledWith({
      name: 'learner-progress',
      params: { id: '7' },
    });

    await recordsButton?.trigger('click');
    expect(routerApi.push).toHaveBeenCalledWith({
      name: 'learner-records',
      params: { id: '7' },
    });
  });

  it('keeps an Element Plus table empty state when no learners match', async () => {
    learnersApi.listLearners.mockResolvedValueOnce({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
    });
    const wrapper = mountLearners();
    await flushPromises();

    expect(wrapper.find('.el-table').exists()).toBe(true);
    expect(wrapper.find('.el-table__empty-block').exists()).toBe(true);
    expect(wrapper.text()).toContain('没有匹配的学员');
    expect(wrapper.find('p.empty').exists()).toBe(false);
  });
});
