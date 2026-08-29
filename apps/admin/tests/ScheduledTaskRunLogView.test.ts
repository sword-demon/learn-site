// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const scheduledTasksApi = vi.hoisted(() => ({
  listScheduledTasks: vi.fn(),
  listScheduledTaskRuns: vi.fn(),
  getScheduledTaskRun: vi.fn(),
}));

vi.mock('@/api/scheduledTasks', () => scheduledTasksApi);

import ScheduledTaskRunLogView from '@/views/scheduled-tasks/ScheduledTaskRunLogView.vue';

describe('ScheduledTaskRunLogView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
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
    const wrapper = mount(ScheduledTaskRunLogView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('执行日志');
    expect(wrapper.text()).toContain('暂无执行记录');
  });
});
