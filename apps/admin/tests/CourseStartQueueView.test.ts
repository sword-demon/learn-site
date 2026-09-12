// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import type { CourseStartQueueItemDTO } from '@learn-site/contracts';

const courseStudentsApi = vi.hoisted(() => ({
  listCourseStartQueue: vi.fn(),
  sendCourseStartReminders: vi.fn(),
}));
const authApi = vi.hoisted(() => ({ hasPermission: vi.fn() }));
type MockRoute = { name: string; params: Record<string, string | string[]> };
const routerApi = vi.hoisted((): { route: MockRoute; push: ReturnType<typeof vi.fn> } => ({
  route: { name: 'course-start-queue', params: { id: '12' } },
  push: vi.fn(),
}));

vi.mock('@/api/courseStudents', () => courseStudentsApi);
vi.mock('@/api/http', () => authApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}));

import CourseStartQueueView from '@/views/students/CourseStartQueueView.vue';

const eligible: CourseStartQueueItemDTO = {
  account_id: 8,
  login: '13912345678',
  nickname: '小王',
  account_status: 'active',
  source: 'purchase',
  entitlement_status: 'active',
  progress_percent: 0,
  startup_state: 'never_opened',
  idle_hours: 80,
  entitled_at: '2026-09-09 10:00:00',
  last_learning_at: null,
  reminder_count: 0,
  last_reminded_at: null,
  can_remind: true,
  reminder_blocked_reason: null,
};

const blocked: CourseStartQueueItemDTO = {
  ...eligible,
  account_id: 9,
  login: '13912345679',
  nickname: '小李',
  can_remind: false,
  reminder_blocked_reason: 'frequency',
  reminder_count: 1,
  last_reminded_at: '2026-09-12 08:00:00',
};

describe('CourseStartQueueView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route = { name: 'course-start-queue', params: { id: '12' } };
    authApi.hasPermission.mockReturnValue(true);
    courseStudentsApi.listCourseStartQueue.mockResolvedValue({
      items: [eligible, blocked],
      total: 2,
      page: 1,
      limit: 20,
      policy: {
        idle_threshold_hours: 72,
        reminder_frequency_hours: 72,
        reminder_cap: 3,
      },
    });
  });

  it('loads policy, source filter, startup state and idle duration', async () => {
    const wrapper = mount(CourseStartQueueView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('72 小时');
    expect(wrapper.text()).toContain('从未打开');
    expect(wrapper.text()).toContain('80 小时');
    expect(wrapper.text()).toContain('仍在冷却期内');
    expect(wrapper.text()).not.toContain('撤销授权');

    const select = wrapper.get('[data-field="startup_state"]');
    await select.get('.el-select__wrapper').trigger('click');
    const option = select
      .findAll('.el-select-dropdown__item')
      .find((item) => item.text() === '从未打开');
    expect(option).toBeDefined();
    await option?.trigger('click');
    await wrapper.get('form.filters').trigger('submit');
    await flushPromises();
    expect(courseStudentsApi.listCourseStartQueue).toHaveBeenLastCalledWith(12, {
      startup_state: 'never_opened',
      sort: 'idle_hours',
      order: 'desc',
      page: 1,
      limit: 20,
    });
    wrapper.unmount();
  });

  it('sends only eligible selected learners and refreshes', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never);
    courseStudentsApi.sendCourseStartReminders.mockResolvedValue({
      sent_count: 1,
      blocked_count: 0,
      dispatch_id: 44,
      outcomes: [{ account_id: 8, sent: true, blocked_reason: null }],
    });
    const wrapper = mount(CourseStartQueueView, { global: { plugins: [installElementPlus] } });
    await flushPromises();
    await wrapper.get('[data-action="send-reminders"]').trigger('click');
    await flushPromises();
    expect(courseStudentsApi.sendCourseStartReminders).not.toHaveBeenCalled();

    wrapper.findComponent({ name: 'ElTable' }).vm.toggleRowSelection(eligible, true);
    await flushPromises();
    await wrapper.get('[data-action="send-reminders"]').trigger('click');
    await flushPromises();
    expect(courseStudentsApi.sendCourseStartReminders).toHaveBeenCalledWith(12, [8]);
    expect(courseStudentsApi.listCourseStartQueue).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });

  it('explains blocked rows instead of sending them', async () => {
    const wrapper = mount(CourseStartQueueView, { global: { plugins: [installElementPlus] } });
    await flushPromises();
    await wrapper.get('[data-action="send-reminders"]').trigger('click');
    await flushPromises();
    expect(courseStudentsApi.sendCourseStartReminders).not.toHaveBeenCalled();
    expect(wrapper.text()).toContain('仍在冷却期内');
    wrapper.unmount();
  });
});
