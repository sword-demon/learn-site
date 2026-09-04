// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ElMessageBox } from 'element-plus';
import { installElementPlus } from '@/plugins/element-plus';

const paymentApi = vi.hoisted(() => ({
  listPaymentWhitelist: vi.fn(),
  addPaymentWhitelist: vi.fn(),
  togglePaymentWhitelist: vi.fn(),
  removePaymentWhitelist: vi.fn(),
  fetchPaymentConfig: vi.fn(),
  updatePaymentConfig: vi.fn(),
}));

vi.mock('@/api/paymentWhitelist', () => paymentApi);
vi.mock('@/api/payment', () => paymentApi);

import PaymentWhitelistView from '@/views/site/PaymentWhitelistView.vue';

const row = {
  id: 1,
  phone_masked: '138****1234',
  enabled: true,
  note: '运营测试',
  created_at: '2026-09-04T10:00:00+08:00',
  updated_at: '2026-09-04T10:00:00+08:00',
};

beforeEach(() => {
  vi.clearAllMocks();
  paymentApi.listPaymentWhitelist.mockResolvedValue({ items: [row], total: 1, page: 1, limit: 20 });
  paymentApi.fetchPaymentConfig.mockResolvedValue({
    enabled: true,
    api_url: 'https://z-pay.cn/',
    pid: '20220726190052',
    merchant_key_masked: '********GIJ',
    notify_url: 'https://learn.example.test/notify',
    return_url: 'https://learn.example.test/return',
    enabled_channels: ['wxpay'],
    whitelist_only: true,
    version: 1,
    updated_at: '2026-09-04T10:00:00+08:00',
  });
  paymentApi.updatePaymentConfig.mockResolvedValue({
    enabled: true,
    api_url: 'https://z-pay.cn/',
    pid: '20220726190052',
    merchant_key_masked: '********GIJ',
    notify_url: 'https://learn.example.test/notify',
    return_url: 'https://learn.example.test/return',
    enabled_channels: ['wxpay'],
    whitelist_only: false,
    version: 2,
    updated_at: '2026-09-04T10:00:00+08:00',
  });
  paymentApi.removePaymentWhitelist.mockResolvedValue(undefined);
});

describe('PaymentWhitelistView', () => {
  it('renders masked rows and confirms removal', async () => {
    vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never);
    const wrapper = mount(PaymentWhitelistView, { global: { plugins: [installElementPlus] } });
    await flushPromises();

    expect(wrapper.text()).toContain('138****1234');
    await wrapper.get('[data-action="remove"]').trigger('click');
    await flushPromises();
    expect(ElMessageBox.confirm).toHaveBeenCalledOnce();
    expect(paymentApi.removePaymentWhitelist).toHaveBeenCalledWith(1);
  });

  it('rejects an invalid phone before calling the API', async () => {
    const wrapper = mount(PaymentWhitelistView, { global: { plugins: [installElementPlus] } });
    await flushPromises();
    await wrapper.get('[data-action="add"]').trigger('click');
    await wrapper.get('input[name="phone"]').setValue('bad');
    await wrapper.findAll('form').at(-1)!.trigger('submit');

    expect(paymentApi.addPaymentWhitelist).not.toHaveBeenCalled();
  });

  it('persists row enablement changes', async () => {
    const wrapper = mount(PaymentWhitelistView, { global: { plugins: [installElementPlus] } });
    await flushPromises();
    await wrapper.get('[data-action="enabled"]').trigger('click');
    await flushPromises();

    expect(paymentApi.togglePaymentWhitelist).toHaveBeenCalledWith(1, false);
  });

  it('syncs the whitelist-only setting through payment config', async () => {
    const wrapper = mount(PaymentWhitelistView, { global: { plugins: [installElementPlus] } });
    await flushPromises();
    await wrapper.get('[data-action="whitelist-only"]').trigger('click');
    await flushPromises();

    expect(paymentApi.updatePaymentConfig).toHaveBeenCalledWith({
      enabled: true,
      api_url: 'https://z-pay.cn/',
      pid: '20220726190052',
      merchant_key: '',
      notify_url: 'https://learn.example.test/notify',
      return_url: 'https://learn.example.test/return',
      enabled_channels: ['wxpay'],
      whitelist_only: false,
      version: 1,
    });
  });
});
