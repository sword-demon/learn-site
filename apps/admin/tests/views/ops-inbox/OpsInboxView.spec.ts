// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { ElMessageBox } from 'element-plus';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { installElementPlus } from '@/plugins/element-plus';

const api = vi.hoisted(() => ({
  fetchOpsInbox: vi.fn(),
  transitionOpsInbox: vi.fn(),
}));
const router = vi.hoisted(() => ({ push: vi.fn(), replace: vi.fn() }));

vi.mock('@/api/opsInbox', () => api);
vi.mock('vue-router', () => ({ useRouter: () => router }));

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
  });

  it('renders rows and navigates through the row deep link', async () => {
    const wrapper = mount(OpsInboxView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('订单 #123 支付状态未知');
    await wrapper.get('button').trigger('click');
    expect(router.push).toHaveBeenCalledWith({ name: 'orders', query: { id: '123' } });
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

  it('rejects a snooze date before calling the API', async () => {
    vi.spyOn(ElMessageBox, 'prompt').mockImplementation(async (_message, _title, options) => {
      const result = options?.inputValidator?.('2020-01-01T00:00:00.000Z');
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
