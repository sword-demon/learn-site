// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import CheckinListView from '@/views/checkins/CheckinListView.vue';

const checkinsApi = vi.hoisted(() => ({
  listCheckins: vi.fn(),
  getCheckin: vi.fn(),
  deleteCheckin: vi.fn(),
}));

vi.mock('@/api/checkins', () => checkinsApi);

describe('Admin CheckinListView', () => {
  beforeEach(() => {
    checkinsApi.listCheckins.mockReset();
    checkinsApi.getCheckin.mockReset();
    checkinsApi.deleteCheckin.mockReset();
    checkinsApi.listCheckins.mockResolvedValue({ items: [], total: 0, page: 1, limit: 20 });
  });

  it('renders rows from API', async () => {
    checkinsApi.listCheckins.mockResolvedValue({
      items: [
        {
          id: 1,
          learner_id: 10,
          learner_display_name: '小明',
          learner_phone_masked: '138****5678',
          checkin_date: '2026-08-30',
          plan_summary: '今日计划',
          checked_in_at: '2026-08-30T09:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    const wrapper = mount(CheckinListView);
    await flushPromises();
    expect(wrapper.text()).toContain('签到管理');
    expect(wrapper.text()).toContain('小明');
    expect(wrapper.text()).toContain('今日计划');
    expect(wrapper.text()).toContain('2026-08-30 09:00:00');
  });

  it('sends learner and date filters to the API', async () => {
    const wrapper = mount(CheckinListView);
    await flushPromises();
    const inputs = wrapper.findAll('input');
    await inputs[0]!.setValue('42');
    await inputs[1]!.setValue('2026-08-01');
    await inputs[2]!.setValue('2026-08-30');
    const queryButton = wrapper.findAll('button').find((button) => button.text().includes('查询'));
    await queryButton!.trigger('click');
    await flushPromises();

    expect(checkinsApi.listCheckins).toHaveBeenLastCalledWith({
      page: 1,
      limit: 20,
      learner_id: 42,
      date_from: '2026-08-01',
      date_to: '2026-08-30',
    });
  });

  it('requires confirmation before deleting and then reloads', async () => {
    checkinsApi.listCheckins.mockResolvedValue({
      items: [
        {
          id: 1,
          learner_id: 10,
          learner_display_name: '小明',
          learner_phone_masked: '138****5678',
          checkin_date: '2026-08-30',
          plan_summary: '今日计划',
          checked_in_at: '2026-08-30T09:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never);
    const wrapper = mount(CheckinListView);
    await flushPromises();
    const deleteButton = wrapper.findAll('button').find((button) => button.text().includes('删除'));
    await deleteButton!.trigger('click');
    await flushPromises();

    expect(ElMessageBox.confirm).toHaveBeenCalledOnce();
    expect(checkinsApi.deleteCheckin).toHaveBeenCalledWith(1);
    expect(checkinsApi.listCheckins).toHaveBeenCalledTimes(2);
  });
});
