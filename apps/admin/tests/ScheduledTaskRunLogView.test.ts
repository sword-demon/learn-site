// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';
import type {
  AdminScheduledTaskRunDetailDTO,
  AdminScheduledTaskRunListItemDTO,
} from '@learn-site/contracts';

const scheduledTasksApi = vi.hoisted(() => ({
  listScheduledTasks: vi.fn(),
  listScheduledTaskRuns: vi.fn(),
  getScheduledTaskRun: vi.fn(),
}));

vi.mock('@/api/scheduledTasks', () => scheduledTasksApi);

import ScheduledTaskRunLogView from '@/views/scheduled-tasks/ScheduledTaskRunLogView.vue';

const runItem: AdminScheduledTaskRunListItemDTO = {
  id: 42,
  task_id: 1,
  task_name: '清理任务',
  trigger_type: 'manual',
  status: 'success',
  started_at: '2026-08-29 15:00:00',
  finished_at: '2026-08-29 15:00:02',
  duration_ms: 1800,
  error_message: null,
  actor_staff_id: 3,
  actor_login: 'admin',
};

const runDetail: AdminScheduledTaskRunDetailDTO = {
  ...runItem,
  context: { deleted: 12, skipped: false },
};

function mountView() {
  return mount(ScheduledTaskRunLogView, {
    attachTo: document.body,
    global: { plugins: [installElementPlus] },
  });
}

describe('ScheduledTaskRunLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    document.body.innerHTML = '';
    scheduledTasksApi.listScheduledTasks.mockResolvedValue([
      {
        id: 1,
        handler_code: 'notification.cleanup',
        name: '清理任务',
        description: null,
        schedule_expression: '0 30 3 * * *',
        enabled: true,
        params: null,
        handler_status: 'available',
        last_run_at: null,
        last_run_status: null,
        next_run_at: null,
        updated_at: '2026-08-28 10:00:00',
      },
    ]);
    scheduledTasksApi.listScheduledTaskRuns.mockResolvedValue({
      items: [],
      total: 0,
      page: 1,
      per_page: 20,
    });
  });

  it('renders empty run log state', async () => {
    const wrapper = mountView();
    await flushPromises();

    expect(wrapper.text()).toContain('执行日志');
    expect(wrapper.text()).toContain('暂无执行记录');
    wrapper.unmount();
  });

  it('opens a structured run detail dialog with labeled context', async () => {
    scheduledTasksApi.listScheduledTaskRuns.mockResolvedValue({
      items: [runItem],
      total: 1,
      page: 1,
      per_page: 20,
    });
    scheduledTasksApi.getScheduledTaskRun.mockResolvedValue(runDetail);

    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('[data-action="detail"]').trigger('click');
    await flushPromises();

    expect(scheduledTasksApi.getScheduledTaskRun).toHaveBeenCalledWith(42);
    const panel = wrapper.get('[data-role="run-detail"]');
    expect(panel.text()).toContain('清理任务');
    expect(panel.text()).toContain('成功');
    expect(panel.text()).toContain('手动');
    expect(panel.text()).toContain('admin');
    expect(panel.text()).toContain('1.8 秒');
    expect(panel.text()).toContain('1800 ms');
    expect(panel.text()).toContain('2026-08-29 15:00:00');
    expect(panel.text()).toContain('2026-08-29 15:00:02');

    const context = wrapper.get('[data-role="run-context"]');
    expect(context.text()).toContain('执行结果');
    expect(context.text()).toContain('删除条数');
    expect(context.text()).toContain('12');
    expect(context.text()).toContain('已跳过');
    expect(context.text()).toContain('否');
    expect(panel.text()).not.toContain('上下文：');
    expect(panel.text()).not.toContain('{"deleted"');
    wrapper.unmount();
  });

  it('shows an error alert for failed runs and system actor for scheduled triggers', async () => {
    const failedItem: AdminScheduledTaskRunListItemDTO = {
      ...runItem,
      id: 7,
      trigger_type: 'schedule',
      status: 'failed',
      duration_ms: 900,
      error_message: 'boom',
      actor_staff_id: null,
      actor_login: null,
    };
    scheduledTasksApi.listScheduledTaskRuns.mockResolvedValue({
      items: [failedItem],
      total: 1,
      page: 1,
      per_page: 20,
    });
    scheduledTasksApi.getScheduledTaskRun.mockResolvedValue({
      ...failedItem,
      context: null,
    });

    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('[data-action="detail"]').trigger('click');
    await flushPromises();

    const panel = wrapper.get('[data-role="run-detail"]');
    expect(panel.text()).toContain('失败');
    expect(panel.text()).toContain('自动');
    expect(panel.text()).toContain('系统');
    expect(panel.text()).toContain('900 ms');
    expect(panel.text()).toContain('boom');
    expect(wrapper.find('[data-role="run-context"]').exists()).toBe(false);
    wrapper.unmount();
  });

  it('keeps the page error when detail loading fails', async () => {
    scheduledTasksApi.listScheduledTaskRuns.mockResolvedValue({
      items: [runItem],
      total: 1,
      page: 1,
      per_page: 20,
    });
    scheduledTasksApi.getScheduledTaskRun.mockRejectedValue(new Error('网络中断'));

    const wrapper = mountView();
    await flushPromises();
    await wrapper.get('[data-action="detail"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('网络中断');
    expect(wrapper.find('[data-role="run-detail"]').exists()).toBe(false);
    wrapper.unmount();
  });
});
