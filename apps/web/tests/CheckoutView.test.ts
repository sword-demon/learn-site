// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const learnerApi = vi.hoisted(() => ({
  fetchCourseDetail: vi.fn(),
  createCourseOrder: vi.fn(),
  fetchOrder: vi.fn(),
  fetchPaymentOptions: vi.fn(),
}));

const couponsApi = vi.hoisted(() => ({
  fetchCheckoutCoupons: vi.fn(),
  fetchClaimableCoupons: vi.fn(),
  claimCoupon: vi.fn(),
  fetchMyCoupons: vi.fn(),
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('@/api/coupons', () => couponsApi);

vi.mock('vue-router', () => ({
  useRoute: () => ({ params: { courseId: 42 } }),
  useRouter: () => ({ replace: vi.fn() }),
  RouterLink: { template: '<a><slot /></a>' },
}));

import CheckoutView from '@/views/checkout/CheckoutView.vue';

const detail = {
  course: {
    id: 42,
    title: 'QA Course',
    summary: 'x',
    cover_url: null,
    teacher_name: 'Tester',
    list_price: 100,
    sale_price: 0,
    sale_start_at: null,
    sale_end_at: null,
    category_id: 1,
    department_id: 1,
    is_published: true,
  },
  chapters: [],
};

const checkout = {
  base_price: 100,
  list_price: 100,
  sale_price: 0,
  items: [
    {
      id: 501,
      name: '满 50 减 15',
      min_amount: 50,
      discount_amount: 15,
      eligible: true,
      ineligible_reason: null,
      payable_preview: 85,
    },
  ],
};

beforeEach(() => {
  vi.clearAllMocks();
  learnerApi.fetchCourseDetail.mockResolvedValue(detail);
  couponsApi.fetchCheckoutCoupons.mockResolvedValue(checkout);
  learnerApi.fetchPaymentOptions.mockResolvedValue({
    enabled: true,
    enabled_channels: ['wxpay', 'alipay'],
  });
  learnerApi.createCourseOrder.mockResolvedValue({
    order_id: 9001,
    status: 'pending',
    list_price_snapshot: 100,
    sale_price_snapshot: 0,
    coupon_discount_snapshot: 15,
    paid_amount: 85,
    learner_coupon_id: 501,
    payment: { type: 'wechat_native', code_url: 'weixin://wxpay/bizpayurl?pr=xxx' },
  });
  learnerApi.fetchOrder.mockResolvedValue({
    order_id: 9001,
    course_id: 42,
    list_price_snapshot: 100,
    sale_price_snapshot: 0,
    coupon_discount_snapshot: 15,
    paid_amount: 85,
    learner_coupon_id: 501,
    currency: 'CNY',
    status: 'pending',
    provider: 'fake',
    succeeded_at: null,
    created_at: '2026-09-01T00:00:00+08:00',
  });
});

describe('CheckoutView coupon integration', () => {
  it('loads checkout options and includes coupon discount row', async () => {
    const wrapper = mount(CheckoutView);
    await flushPromises();
    expect(couponsApi.fetchCheckoutCoupons).toHaveBeenCalledWith(42);
    const html = wrapper.html();
    expect(html).toContain('满 50 减 15');
    expect(html).toContain('应付金额');
    wrapper.unmount();
  });

  it('clears a missing coupon and shows a friendly submission error', async () => {
    learnerApi.createCourseOrder.mockRejectedValueOnce(new Error('COUPON_NOT_FOUND'));
    const wrapper = mount(CheckoutView);
    await flushPromises();

    const vm = wrapper.vm as unknown as {
      selectedCouponId: number | null;
      agreed: boolean;
      submitOrder: () => Promise<void>;
      submitError: string;
    };
    vm.selectedCouponId = 501;
    vm.agreed = true;
    await vm.submitOrder();

    expect(learnerApi.createCourseOrder).toHaveBeenCalledWith(42, 501, 'wxpay');
    expect(vm.selectedCouponId).toBeNull();
    expect(vm.submitError).toBe('所选优惠券已失效,请重新选择优惠券。');
    expect(wrapper.text()).toContain('所选优惠券已失效,请重新选择优惠券。');
    expect(couponsApi.fetchCheckoutCoupons).toHaveBeenCalledTimes(2);
    wrapper.unmount();
  });

  it('disables unavailable channels and selects the first enabled channel', async () => {
    learnerApi.fetchPaymentOptions.mockResolvedValueOnce({
      enabled: true,
      enabled_channels: ['alipay'],
    });
    const wrapper = mount(CheckoutView);
    await flushPromises();

    expect(wrapper.get('[data-action="pay-wechat"]').classes()).toContain('is-disabled');
    expect(wrapper.get('[data-action="pay-alipay"]').classes()).not.toContain('is-disabled');
    expect((wrapper.vm as unknown as { paymentMethod: string }).paymentMethod).toBe('alipay');
    wrapper.unmount();
  });

  it('sends the selected alipay channel when submitting', async () => {
    const wrapper = mount(CheckoutView);
    await flushPromises();

    const vm = wrapper.vm as unknown as {
      paymentMethod: string;
      agreed: boolean;
      submitOrder: () => Promise<void>;
    };
    vm.paymentMethod = 'alipay';
    vm.agreed = true;
    await vm.submitOrder();

    expect(learnerApi.createCourseOrder).toHaveBeenCalledWith(42, null, 'alipay');
    wrapper.unmount();
  });

  it('clears a coupon that becomes ineligible after refresh', async () => {
    const wrapper = mount(CheckoutView);
    await flushPromises();

    const vm = wrapper.vm as unknown as {
      selectedCouponId: number | null;
      loadCoupons: () => Promise<void>;
    };
    vm.selectedCouponId = 501;
    couponsApi.fetchCheckoutCoupons.mockResolvedValueOnce({
      ...checkout,
      items: [{ ...checkout.items[0], eligible: false, ineligible_reason: 'COUPON_EXPIRED' }],
    });
    await vm.loadCoupons();

    expect(vm.selectedCouponId).toBeNull();
    wrapper.unmount();
  });
});
