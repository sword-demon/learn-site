// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { ElMessage } from 'element-plus';
import { installElementPlus } from '@/plugins/element-plus';

const couponsApi = vi.hoisted(() => ({
  listCoupons: vi.fn(),
  createCoupon: vi.fn(),
  disableCoupon: vi.fn(),
  grantCoupon: vi.fn(),
  patchCoupon: vi.fn(),
  listRedemptions: vi.fn(),
}));

const catalogApi = vi.hoisted(() => ({
  listCategoriesFlat: vi.fn(),
  listCourses: vi.fn(),
}));

vi.mock('@/api/coupons', () => couponsApi);
vi.mock('@/api/catalog', () => catalogApi);

import CouponListView from '@/views/coupons/CouponListView.vue';

const campaigns = [
  {
    id: 1,
    name: 'QA 满 50 减 10',
    scope_type: 'all' as const,
    scope_category_ids: [],
    scope_course_ids: [],
    min_amount: 0,
    discount_amount: 10,
    claim_mode: 'public' as const,
    claim_starts_at: '2026-09-01T00:00:00+08:00',
    claim_ends_at: '2026-09-30T23:59:59+08:00',
    use_ends_at: null,
    total_quota: 100,
    claimed_count: 3,
    used_count: 1,
    per_learner_claim_limit: 1,
    per_learner_use_limit: 1,
    status: 'active' as const,
    created_by: 1,
    created_at: '2026-09-01T00:00:00+08:00',
    updated_at: '2026-09-01T00:00:00+08:00',
  },
];

const category = {
  id: 7,
  parent_id: 0,
  name: '编程基础',
  path: '/7',
  depth: 1,
  sort: 0,
  status: 'enabled' as const,
  created_at: '2026-09-01 00:00:00',
  updated_at: '2026-09-01 00:00:00',
};

const course = {
  id: 8,
  department_id: 1,
  category_id: category.id,
  title: 'TypeScript 深入实践',
  cover_url: null,
  teacher_name: '测试讲师',
  summary: '测试课程',
  intro_rich_text: '',
  status: 'published' as const,
  price_mode: 'paid' as const,
  list_price: 99,
  sale_price: 99,
  sale_start_at: null,
  sale_end_at: null,
  created_by_staff_id: 1,
  created_at: '2026-09-01 00:00:00',
  updated_at: '2026-09-01 00:00:00',
};

beforeEach(() => {
  vi.clearAllMocks();
  couponsApi.listCoupons.mockResolvedValue({ items: campaigns, total: 1, page: 1, limit: 20 });
  couponsApi.createCoupon.mockResolvedValue(campaigns[0]);
  couponsApi.patchCoupon.mockResolvedValue(campaigns[0]);
  couponsApi.grantCoupon.mockResolvedValue({ granted: 1, skipped: 0, items: [] });
  catalogApi.listCategoriesFlat.mockResolvedValue({
    items: [category],
    total: 1,
    page: 1,
    limit: 100,
  });
  catalogApi.listCourses.mockResolvedValue({ items: [course], total: 1, page: 1, limit: 20 });
  couponsApi.listRedemptions.mockResolvedValue({
    items: [
      {
        redemption_id: 1,
        campaign_id: 1,
        campaign_name: 'QA 满 50 减 10',
        learner_id: 101,
        learner_masked_phone: '138****5678',
        course_id: 1,
        course_title: 'QA Course',
        order_id: 9001,
        discount_amount: 10,
        used_at: '2026-09-15T12:00:00+08:00',
      },
    ],
    total: 1,
    page: 1,
    limit: 50,
  });
});

describe('CouponListView', () => {
  it('loads and renders the coupon list', async () => {
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(couponsApi.listCoupons).toHaveBeenCalled();
    const html = wrapper.html();
    expect(html).toContain('QA 满 50 减 10');
    expect(html).toContain('满 0 减 10');
    expect(html).toContain('公开领取');
    wrapper.unmount();
  });

  it('loads redemptions when fetching by campaign_id', async () => {
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    expect(couponsApi.listRedemptions).toBeDefined();
    wrapper.unmount();
  });

  it('uses Element Plus datetime pickers and placeholders in the coupon dialog', async () => {
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="create-coupon"]').trigger('click');

    expect(wrapper.findAll('input[type="datetime-local"]')).toHaveLength(0);
    expect(wrapper.findAllComponents({ name: 'ElDatePicker' })).toHaveLength(3);
    expect(wrapper.get('input[data-field="name"]').attributes('placeholder')).toBe(
      '请输入优惠券名称',
    );
    expect(
      wrapper.findAll('.el-date-editor input').map((input) => input.attributes('placeholder')),
    ).toEqual(['选择领取开始时间', '选择领取结束时间', '选择使用截止时间']);
    wrapper.unmount();
  });

  it('loads category and course options for selectable coupon scopes', async () => {
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="create-coupon"]').trigger('click');
    await flushPromises();

    expect(catalogApi.listCategoriesFlat).toHaveBeenCalledWith({ page: 1, limit: 100 });
    expect(catalogApi.listCourses).toHaveBeenCalledWith({ page: 1, limit: 20 });

    const dialog = wrapper.get('[data-dialog="create-coupon"]');
    await dialog.get('[data-field="scope_type"] label:nth-child(2) input').setValue(true);
    await flushPromises();
    const categorySelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((select) => select.attributes('data-field') === 'scope_category_ids');
    const courseSelect = wrapper
      .findAllComponents({ name: 'ElSelect' })
      .find((select) => select.attributes('data-field') === 'scope_course_ids');
    expect(categorySelect).toBeDefined();
    expect(categorySelect?.props('multiple')).toBe(true);
    expect(categorySelect?.props('filterable')).toBe(true);
    expect(courseSelect).toBeUndefined();

    await dialog.get('[data-field="scope_type"] label:nth-child(3) input').setValue(true);
    await flushPromises();
    expect(
      wrapper
        .findAllComponents({ name: 'ElSelect' })
        .find((select) => select.attributes('data-field') === 'scope_course_ids')
        ?.props('remote'),
    ).toBe(true);
    wrapper.unmount();
  });

  it('opens the edit dialog and patches the mutable coupon fields', async () => {
    const campaign = campaigns[0]!;
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="edit"]').trigger('click');
    await flushPromises();

    expect(wrapper.text()).toContain('编辑优惠券');
    await wrapper
      .get('[data-dialog="create-coupon"] input[data-field="name"]')
      .setValue('更新后的优惠券');
    await wrapper.get('[data-action="submit-coupon"]').trigger('click');
    await flushPromises();

    expect(couponsApi.patchCoupon).toHaveBeenCalledWith(
      campaign.id,
      expect.objectContaining({
        name: '更新后的优惠券',
        expected_updated_at: campaign.updated_at,
      }),
    );
    expect(couponsApi.createCoupon).not.toHaveBeenCalled();
    wrapper.unmount();
  });

  it('shows a friendly Element Plus message when saving fails validation', async () => {
    const error = vi.spyOn(ElMessage, 'error').mockImplementation(() => undefined as never);
    couponsApi.createCoupon.mockRejectedValueOnce(
      Object.assign(new Error('Request failed'), {
        response: {
          data: { error: { code: 'VALIDATION_FAILED', message: 'COUPON_DATE_INVALID' } },
        },
      }),
    );
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="create-coupon"]').trigger('click');
    await wrapper
      .get('[data-dialog="create-coupon"] input[data-field="name"]')
      .setValue('错误提示测试');
    await wrapper.get('[data-action="submit-coupon"]').trigger('click');
    await flushPromises();

    expect(error).toHaveBeenCalledWith(
      '时间设置无效：领取结束时间需晚于开始时间，使用截止时间不得早于领取结束时间',
    );
    expect(wrapper.find('.coupons__alert').exists()).toBe(false);
    error.mockRestore();
    wrapper.unmount();
  });

  it('blocks saving when the coupon date window is invalid', async () => {
    const warning = vi.spyOn(ElMessage, 'warning').mockImplementation(() => undefined as never);
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="create-coupon"]').trigger('click');
    await wrapper
      .get('[data-dialog="create-coupon"] input[data-field="name"]')
      .setValue('日期校验测试');

    const datePickers = wrapper.findAllComponents({ name: 'ElDatePicker' });
    datePickers[0]!.vm.$emit('update:modelValue', '2026-09-30 00:00:00');
    datePickers[1]!.vm.$emit('update:modelValue', '2026-09-01 00:00:00');
    await wrapper.get('[data-action="submit-coupon"]').trigger('click');
    await flushPromises();

    expect(warning).toHaveBeenCalledWith('领取结束时间必须晚于领取开始时间');
    expect(couponsApi.createCoupon).not.toHaveBeenCalled();
    warning.mockRestore();
    wrapper.unmount();
  });

  it('sends coupon dates with the business timezone', async () => {
    const wrapper = mount(CouponListView, {
      global: { plugins: [installElementPlus] },
    });
    await flushPromises();
    await wrapper.get('[data-action="create-coupon"]').trigger('click');
    await wrapper
      .get('[data-dialog="create-coupon"] input[data-field="name"]')
      .setValue('时区测试');

    const datePickers = wrapper.findAllComponents({ name: 'ElDatePicker' });
    datePickers[0]!.vm.$emit('update:modelValue', '2026-09-01 10:00:00');
    datePickers[1]!.vm.$emit('update:modelValue', '2026-09-30 18:00:00');
    await wrapper.get('[data-action="submit-coupon"]').trigger('click');
    await flushPromises();

    expect(couponsApi.createCoupon).toHaveBeenCalledWith(
      expect.objectContaining({
        claim_starts_at: '2026-09-01T10:00:00+08:00',
        claim_ends_at: '2026-09-30T18:00:00+08:00',
        use_ends_at: null,
      }),
    );
    wrapper.unmount();
  });
});
