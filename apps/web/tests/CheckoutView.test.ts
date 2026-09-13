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
  // 注意：这里导出的 RouterLink 只对 `import { RouterLink } from 'vue-router'` 生效。
  // 模板里的 <router-link> 走运行时 resolveComponent，必须在 mount 时用
  // global.stubs 注册，否则 Vue 会打印 "Failed to resolve component: router-link"。
  RouterLink: { template: '<a><slot /></a>' },
}));

/** 模板 <router-link> 的全局替身，交给每个 mount 的 global.stubs 使用。 */
const RouterLinkStub = { template: '<a><slot /></a>' };

/** 统一的挂载选项：补齐 <router-link> 替身，消除组件解析告警。 */
const MOUNT_OPTIONS = { global: { stubs: { RouterLink: RouterLinkStub } } };

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
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
    await flushPromises();
    expect(couponsApi.fetchCheckoutCoupons).toHaveBeenCalledWith(42);
    const html = wrapper.html();
    expect(html).toContain('满 50 减 15');
    expect(html).toContain('应付金额');
    wrapper.unmount();
  });

  it('clears a missing coupon and shows a friendly submission error', async () => {
    learnerApi.createCourseOrder.mockRejectedValueOnce(new Error('COUPON_NOT_FOUND'));
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
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
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
    await flushPromises();

    expect(wrapper.get('[data-action="pay-wechat"]').classes()).toContain('is-disabled');
    expect(wrapper.get('[data-action="pay-alipay"]').classes()).not.toContain('is-disabled');
    expect((wrapper.vm as unknown as { paymentMethod: string }).paymentMethod).toBe('alipay');
    wrapper.unmount();
  });

  it('sends the selected alipay channel when submitting', async () => {
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
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
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
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

  // 回归：单选组用哨兵值 0 表示「不使用」。Element Plus 2.14 用 isNil 判断 value
  // 缺省，`:value="null"` 会被误判成未传 value 并打印 label 弃用告警。
  it('maps the no-coupon sentinel to a null coupon id', async () => {
    const wrapper = mount(CheckoutView, MOUNT_OPTIONS);
    await flushPromises();

    // 模板层：渲染出的单选 input 带哨兵值 0，而不是被省略的 null
    expect(wrapper.get('[data-action="no-coupon"] input').attributes('value')).toBe('0');

    const vm = wrapper.vm as unknown as {
      selectedCouponId: number | null;
      couponRadioValue: number;
    };

    // 读取方向：未选券时单选组读到哨兵；选中真实券后同步为券 id
    expect(vm.couponRadioValue).toBe(0);
    vm.selectedCouponId = 501;
    expect(vm.couponRadioValue).toBe(501);
    // 写回方向：命中哨兵即清空业务值；真实券 id 原样写入
    vm.couponRadioValue = 0;
    expect(vm.selectedCouponId).toBeNull();
    vm.couponRadioValue = 501;
    expect(vm.selectedCouponId).toBe(501);
    wrapper.unmount();
  });
});
