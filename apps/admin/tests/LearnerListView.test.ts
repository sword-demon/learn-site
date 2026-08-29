// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const learnersApi = vi.hoisted(() => ({
  listLearners: vi.fn(),
  kickLearner: vi.fn(),
  resetLearnerPassword: vi.fn(),
}));

const orgApi = vi.hoisted(() => ({
  listDepartments: vi.fn(),
}));

vi.mock('@/api/learners', () => learnersApi);
vi.mock('@/api/org', () => orgApi);

import LearnerListView from '@/views/students/LearnerListView.vue';

describe('LearnerListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    orgApi.listDepartments.mockResolvedValue({
      items: [
        {
          id: 3,
          parent_id: 0,
          name: '产品技术部',
          path: '3',
          depth: 1,
          sort: 1,
          status: 'enabled',
          created_at: '2026-08-28 09:00:00',
          updated_at: '2026-08-28 09:00:00',
        },
        {
          id: 4,
          parent_id: 0,
          name: '市场运营部',
          path: '4',
          depth: 1,
          sort: 2,
          status: 'disabled',
          created_at: '2026-08-28 09:00:00',
          updated_at: '2026-08-28 09:00:00',
        },
      ],
    });
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

  it('renders learning and successful purchase summaries', async () => {
    const wrapper = mountLearners();
    await flushPromises();

    expect(wrapper.find('.filter-control').exists()).toBe(true);
    expect(wrapper.text()).toContain('3 门 / 完成 1 门');
    expect(wrapper.text()).toContain('2 单 / ¥198.00');
  });

  it('loads department options and sends the selected department id when querying', async () => {
    const wrapper = mountLearners();
    await flushPromises();

    expect(orgApi.listDepartments).toHaveBeenCalledOnce();

    const departmentField = wrapper.get('[data-field="department_id"]');
    await departmentField.get('.el-select__wrapper').trigger('click');
    await flushPromises();

    const departmentOption = departmentField
      .findAll('.el-select-dropdown__item')
      .find((item) => item.text().includes('产品技术部'));
    expect(departmentOption).toBeDefined();
    await departmentOption?.trigger('click');
    await flushPromises();

    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();

    expect(learnersApi.listLearners).toHaveBeenLastCalledWith({
      department_id: 3,
      page: 1,
      limit: 20,
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
