// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ElMessage } from 'element-plus';

const paymentApi = vi.hoisted(() => ({
  fetchPaymentConfig: vi.fn(),
  updatePaymentConfig: vi.fn(),
}));

vi.mock('@/api/payment', () => paymentApi);

import PaymentConfigView from '@/views/site/PaymentConfigView.vue';

const config = {
  enabled: true,
  api_url: 'https://z-pay.cn/',
  pid: '20220726190052',
  merchant_key_masked: '********gGIJ',
  notify_url: 'https://learn.example.test/notify',
  return_url: 'https://learn.example.test/return',
  enabled_channels: ['wxpay', 'alipay'] as const,
  whitelist_only: true,
  version: 1,
  updated_at: '2026-09-04T10:00:00+00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
  paymentApi.fetchPaymentConfig.mockResolvedValue(config);
  paymentApi.updatePaymentConfig.mockResolvedValue(config);
});

describe('PaymentConfigView', () => {
  it('loads fields and never renders the plaintext merchant key', async () => {
    const wrapper = mount(PaymentConfigView);
    await flushPromises();

    expect(wrapper.get('input[name="pid"]').element).toHaveProperty('value', config.pid);
    expect(wrapper.text()).toContain(config.merchant_key_masked);
    expect(wrapper.text()).not.toContain('merchant-secret');
  });

  it('submits the typed update payload while keeping an existing key', async () => {
    const wrapper = mount(PaymentConfigView);
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(paymentApi.updatePaymentConfig).toHaveBeenCalledWith({
      enabled: true,
      api_url: config.api_url,
      pid: config.pid,
      merchant_key: '',
      notify_url: config.notify_url,
      return_url: config.return_url,
      enabled_channels: ['wxpay', 'alipay'],
      whitelist_only: true,
      version: 1,
    });
  });

  it('stops before the API call when Zod rejects the form', async () => {
    const wrapper = mount(PaymentConfigView);
    await flushPromises();
    await wrapper.get('input[name="api_url"]').setValue('invalid-url');
    await wrapper.get('form').trigger('submit');

    expect(paymentApi.updatePaymentConfig).not.toHaveBeenCalled();
  });

  it('shows a success toast after saving', async () => {
    const success = vi.spyOn(ElMessage, 'success').mockImplementation(() => undefined as never);
    const wrapper = mount(PaymentConfigView);
    await flushPromises();
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(success).toHaveBeenCalledWith('支付配置已保存');
    success.mockRestore();
  });
});
