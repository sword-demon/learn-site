// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchOpsInbox: vi.fn(),
  transitionOpsInbox: vi.fn(),
}));
const orgApi = vi.hoisted(() => ({
  listStaff: vi.fn(),
}));
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));

vi.mock('@/api/opsInbox', () => api);
vi.mock('@/api/org', () => orgApi);
vi.mock('@/views/ops-inbox/ContentTodoDrawer.vue', () => ({
  default: {
    name: 'ContentTodoDrawer',
    props: ['visible', 'todoId'],
    template: '<div class="content-todo-drawer-stub" />',
  },
}));
vi.mock('vue-router', () => ({
  useRouter: () => router,
  useRoute: () => ({ query: {}, path: '/ops-inbox' }),
}));

import OpsInboxView from '@/views/ops-inbox/OpsInboxView.vue';

const row = {
  id: 'payment_unknown:123',
  source_type: 'payment_unknown' as const,
  source_key: '123',
  title: '订单 #123 支付状态未知',
  severity: 'critical' as const,
  age_seconds: 7200,
  age_label: '2 小时',
  weight: 100,
  impact: { learners: 1, orders_amount_cents: 9900 },
  suggested_action: '核对支付回调并处理订单',
  deep_link: { name: 'orders', query: { id: '123' } },
  state: 'open' as const,
  assignee_id: null,
  snooze_until: null,
  last_error_code: null,
  retry_count: 0,
};

describe('OpsInboxView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    api.fetchOpsInbox.mockResolvedValue({
      items: [row],
      total: 1,
      page: 1,
      limit: 20,
      counts_by_source: { payment_unknown: 1 },
    });
    api.transitionOpsInbox.mockResolvedValue({
      ok: true,
      state: 'resolved',
      updated_at: '2026-09-05T16:00:00+08:00',
    });
    orgApi.listStaff.mockResolvedValue({
      items: [
        {
          account_id: 9,
          login: 'ops-owner',
          display_name: '运营甲',
          is_super_admin: false,
          department_id: 1,
          department_name: '运营',
          department_status: 'enabled',
          account_status: 'active',
          must_change_password: false,
          last_login_at: '2026-09-13 10:00:00',
          created_at: '2026-09-01 10:00:00',
          updated_at: '2026-09-13 10:00:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
  });

  it('renders rows and navigates through the row deep link', async () => {
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('订单 #123 支付状态未知');
    expect(wrapper.find('.filter-form').exists()).toBe(true);
    expect(wrapper.findAll('.filter-form .el-form-item').length).toBeGreaterThanOrEqual(5);
    await wrapper.get('button').trigger('click');
    expect(router.push).toHaveBeenCalledWith({ name: 'orders', query: { id: '123' } });
    wrapper.unmount();
  });

  it('keeps both row action buttons inside one aligned flex container', async () => {
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    // 操作列曾出现「跳转处理」与 dropdown 包裹的「操作」垂直错位且无间距,
    // 现统一装进 .row-actions flex 容器。
    const actions = wrapper.get('.row-actions');
    expect(actions.findAll('button').length).toBe(2);
    expect(actions.find('.el-dropdown').exists()).toBe(true);
    wrapper.unmount();
  });

  it('optimistically removes an acknowledged row', async () => {
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('.el-dropdown .el-button').trigger('click');
    await flushPromises();
    const action = document.body.querySelector('.el-dropdown-menu__item');
    expect(action).not.toBeNull();
    await (action as HTMLElement).click();
    await flushPromises();

    expect(api.transitionOpsInbox).toHaveBeenCalledWith(row.id, { to_state: 'resolved' });
    expect(wrapper.text()).not.toContain(row.title);
    wrapper.unmount();
  });

  it('does not resolve a content source without a content todo', async () => {
    api.fetchOpsInbox.mockResolvedValue({
      items: [
        {
          ...row,
          id: 'question_pending:77',
          source_type: 'question_pending',
          source_key: '77',
          title: '待回答问题：为什么没有例子？',
          content_todo_id: null,
          deep_link: { name: 'questions' },
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
      counts_by_source: { question_pending: 1 },
    });
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('.el-dropdown .el-button').trigger('click');
    await flushPromises();
    const action = document.body.querySelector('.el-dropdown-menu__item');
    expect(action).not.toBeNull();
    await (action as HTMLElement).click();
    await flushPromises();
    expect(api.transitionOpsInbox).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('opens the content todo drawer instead of the original deep link', async () => {
    api.fetchOpsInbox.mockResolvedValue({
      items: [
        {
          ...row,
          id: 'question_pending:77',
          source_type: 'question_pending',
          source_key: '77',
          title: '待回答问题：为什么没有例子？',
          content_todo_id: 21,
          content_workflow_status: 'untriaged',
          deep_link: { name: 'ops-inbox', query: { content_todo_id: 21 } },
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
      counts_by_source: { question_pending: 1 },
    });
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('button').trigger('click');
    expect(router.push).not.toHaveBeenCalled();
    expect(wrapper.find('.content-todo-drawer-stub').exists()).toBe(true);
    wrapper.unmount();
  });

  it('assigns from the paged staff picker instead of a raw account id prompt', async () => {
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('.el-dropdown .el-button').trigger('click');
    await flushPromises();
    const assign = document.body.querySelectorAll('.el-dropdown-menu__item')[2];
    await (assign as HTMLElement).click();
    await flushPromises();

    expect(wrapper.text()).toContain('从在职员工列表中选择处理人');
    expect(orgApi.listStaff).toHaveBeenCalledWith({ status: 'active', page: 1, limit: 20 });

    const vm = wrapper.vm as unknown as {
      assignStaffId: number | null;
      confirmAssign: () => Promise<void>;
    };
    vm.assignStaffId = 9;
    await vm.confirmAssign();
    await flushPromises();
    expect(api.transitionOpsInbox).toHaveBeenCalledWith(row.id, {
      to_state: 'assigned',
      assignee_id: 9,
    });
    wrapper.unmount();
  });

  it('prefills wall-clock Beijing time and submits ISO to the API', async () => {
    let capturedOptions:
      { inputValue?: string; inputValidator?: (value: string) => true | string } | undefined;
    vi.spyOn(ElMessageBox, 'prompt').mockImplementation(async (_message, _title, options) => {
      capturedOptions = options as typeof capturedOptions;
      return { value: '2026-09-14 15:30:00' } as never;
    });
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('.el-dropdown .el-button').trigger('click');
    await flushPromises();
    const snooze = document.body.querySelectorAll('.el-dropdown-menu__item')[1];
    await (snooze as HTMLElement).click();
    await flushPromises();

    expect(String(capturedOptions?.inputValue)).toMatch(/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/);
    expect(api.transitionOpsInbox).toHaveBeenCalledWith(row.id, {
      to_state: 'snoozed',
      snooze_until: '2026-09-14T15:30:00+08:00',
    });
    wrapper.unmount();
  });

  it('rejects a snooze date before calling the API', async () => {
    vi.spyOn(ElMessageBox, 'prompt').mockImplementation(async (_message, _title, options) => {
      const result = options?.inputValidator?.('2020-01-01 00:00:00');
      expect(result).toBe('到期时间需晚于当前时间 60 秒');
      throw 'cancel';
    });
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('.el-dropdown .el-button').trigger('click');
    await flushPromises();
    const snooze = document.body.querySelectorAll('.el-dropdown-menu__item')[1];
    await (snooze as HTMLElement).click();
    await flushPromises();

    expect(api.transitionOpsInbox).not.toHaveBeenCalled();
    wrapper.unmount();
  });
});
