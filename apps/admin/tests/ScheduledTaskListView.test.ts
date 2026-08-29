// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const scheduledTasksApi = vi.hoisted(() => ({
  listScheduledTasks: vi.fn(),
  runScheduledTask: vi.fn(),
}));

vi.mock('@/api/scheduledTasks', () => scheduledTasksApi);

vi.mock('vue-router', () => ({
  useRouter: () => ({ push: vi.fn() }),
}));

import ScheduledTaskListView from '@/views/scheduled-tasks/ScheduledTaskListView.vue';

describe('ScheduledTaskListView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    scheduledTasksApi.listScheduledTasks.mockResolvedValue([
      {
        id: 1,
        handler_code: 'notification.cleanup',
        name: '学员消息收件箱过期清理',
        description: '删除过期记录',
        schedule_expression: '0 30 3 * * *',
        enabled: true,
        params: { batch_size: 500 },
        handler_status: 'available',
        last_run_at: null,
        last_run_status: null,
        next_run_at: '2026-08-30 03:30:00',
        updated_at: '2026-08-28 10:00:00',
      },
    ]);
  });

  it('renders scheduled task list with empty last-run state', async () => {
    const wrapper = mount(ScheduledTaskListView, {
      global: {
        plugins: [installElementPlus],
        stubs: { ScheduledTaskEditDialog: true },
      },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('自动任务');
    expect(wrapper.text()).toContain('学员消息收件箱过期清理');
    expect(wrapper.text()).toContain('尚未执行');
  });
});
