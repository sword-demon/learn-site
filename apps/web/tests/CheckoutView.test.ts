// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type {
  CreateOrderResponseDTO,
  OrderDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';

const learnerApi = vi.hoisted(() => ({
  fetchCourseDetail: vi.fn(),
  createCourseOrder: vi.fn(),
  fetchOrder: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  route: { params: { courseId: '21' }, fullPath: '/checkout/21', query: {} },
}));

vi.mock('@/api/learner', () => learnerApi);
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: vi.fn() }),
}));

import CheckoutView from '@/views/checkout/CheckoutView.vue';

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

const detail: PublicCourseDetailDTO = {
  course: {
    id: 21,
    category_id: 3,
    category_name: '工程实践',
    title: 'TypeScript 进阶',
    cover_url: null,
    teacher_name: '陈老师',
    summary: '从基础类型到高级类型编程',
    intro_html: '<p>课程介绍</p>',
    price_mode: 'paid',
    list_price: 299.0,
    sale_price: 199.0,
    sale_start_at: '2026-08-01 00:00:00',
    sale_end_at: '2026-12-31 23:59:59',
    viewer_authorized: false,
    viewer_entitlement_status: null,
    viewer_entitlement_source: null,
    viewer_revoked_reason: null,
    viewer_can_rejoin: false,
    learner_count: 48,
    created_at: '2026-08-20 10:00:00',
  },
  chapters: [],
};

const createdOrder: CreateOrderResponseDTO = {
  order_id: 501,
  status: 'pending',
  payment: {
    type: 'qrcode',
    code_url: 'weixin://wxpay/bizpayurl?pr=abc123',
    out_trade_no: 'O-501',
    amount: 199.0,
    currency: 'CNY',
    provider: 'wechat',
  },
};

const pendingOrder: OrderDTO = {
  order_id: 501,
  course_id: 21,
  list_price_snapshot: 299.0,
  sale_price_snapshot: 199.0,
  paid_amount: 0,
  currency: 'CNY',
  status: 'pending',
  provider: 'wechat',
  succeeded_at: null,
  created_at: '2026-08-30 10:00:00',
};

describe('CheckoutView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route.params.courseId = '21';
    learnerApi.fetchCourseDetail.mockResolvedValue(detail);
    learnerApi.createCourseOrder.mockResolvedValue(createdOrder);
    learnerApi.fetchOrder.mockResolvedValue(pendingOrder);
  });

  it('renders course summary, both payment methods, and a disabled submit until agreement', async () => {
    const wrapper = mount(CheckoutView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect(learnerApi.fetchCourseDetail).toHaveBeenCalledWith(21);
    expect(wrapper.text()).toContain('TypeScript 进阶');
    expect(wrapper.text()).toContain('讲师 · 陈老师');
    expect(wrapper.text()).toContain('¥ 299.00');
    expect(wrapper.text()).toContain('¥ 199.00');
    expect(wrapper.text()).toContain('微信支付');
    expect(wrapper.text()).toContain('支付宝');
    expect(wrapper.text()).toContain('不支持退款');

    const submit = wrapper.get('[data-action="create-order"]');
    expect(submit.attributes('disabled')).toBeDefined();

    // Toggle alipay; submit label should reflect it
    await wrapper.get('[data-action="pay-alipay"]').trigger('click');
    expect(wrapper.text()).toContain('提交并使用支付宝');

    // Tick agreement to unlock submit
    await wrapper.get('input[type="checkbox"]').setValue(true);
    expect(submit.attributes('disabled')).toBeUndefined();
  });

  it('submits an order and surfaces status + payment code_url', async () => {
    const wrapper = mount(CheckoutView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('[data-action="create-order"]').trigger('click');
    await flushPromises();

    expect(learnerApi.createCourseOrder).toHaveBeenCalledWith(21);
    expect(learnerApi.fetchOrder).toHaveBeenCalledWith(501);
    expect(wrapper.text()).toContain('等待支付');
    expect(wrapper.text()).toContain('weixin://wxpay/bizpayurl?pr=abc123');
    expect(wrapper.text()).toContain('订单 #501');
    expect(wrapper.find('[data-action="refresh-order"]').exists()).toBe(true);
  });

  it('treats the promo code input as UI-only and never sends it to the backend', async () => {
    const wrapper = mount(CheckoutView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    await wrapper.get('[data-testid="promo-input"]').setValue('TRYSTEP10');
    await wrapper.get('[data-action="apply-promo"]').trigger('click');
    await wrapper.get('input[type="checkbox"]').setValue(true);
    await wrapper.get('[data-action="create-order"]').trigger('click');
    await flushPromises();

    expect(learnerApi.createCourseOrder).toHaveBeenCalledWith(21);
    // ponytail: applyPromo is local-stub — only 21 must be sent, no promo payload leaks into the call.
    const callArgs = learnerApi.createCourseOrder.mock.calls[0];
    expect(callArgs).toHaveLength(1);
  });

  it('shows the error retry path when the course cannot be loaded', async () => {
    learnerApi.fetchCourseDetail.mockRejectedValueOnce(
      Object.assign(new Error('NOT_FOUND'), { code: 'NOT_FOUND' }),
    );
    const wrapper = mount(CheckoutView, {
      global: { stubs: { RouterLink: RouterLinkStub } },
    });
    await flushPromises();

    expect(wrapper.text()).toContain('课程不存在或已下架。');
    expect(wrapper.find('[data-action="retry"]').exists()).toBe(true);
    expect(wrapper.text()).not.toContain('TypeScript 进阶');
  });
});