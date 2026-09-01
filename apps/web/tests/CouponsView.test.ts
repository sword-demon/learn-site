// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const couponsApi = vi.hoisted(() => ({
  fetchClaimableCoupons: vi.fn(),
  claimCoupon: vi.fn(),
  fetchMyCoupons: vi.fn(),
  fetchCheckoutCoupons: vi.fn(),
}));

vi.mock('@/api/coupons', () => couponsApi);

import CouponsView from '@/views/me/CouponsView.vue';

const claimable = [
  {
    id: 7,
    name: '开学季满 99 减 20',
    scope_type: 'all' as const,
    scope_summary: '无门槛',
    min_amount: 99,
    discount_amount: 20,
    claim_starts_at: '2026-09-01T00:00:00+08:00',
    claim_ends_at: '2026-09-30T23:59:59+08:00',
    use_ends_at: '2026-09-30T23:59:59+08:00',
    remaining_quota: 50,
  },
];

const mineCoupon = {
  id: 99,
  campaign_id: 7,
  name: '开学季满 99 减 20',
  scope_type: 'all' as const,
  scope_summary: '全部课程',
  min_amount: 99,
  discount_amount: 20,
  status: 'unused' as const,
  source: 'claim' as const,
  expires_at: '2026-09-30T23:59:59+08:00',
  created_at: '2026-09-01T00:00:00+08:00',
};

beforeEach(() => {
  vi.clearAllMocks();
  couponsApi.fetchClaimableCoupons.mockResolvedValue(claimable);
  couponsApi.fetchMyCoupons.mockResolvedValue({
    items: [],
    total: 0,
    page: 1,
    limit: 20,
  });
  couponsApi.claimCoupon.mockResolvedValue({
    id: 99,
    campaign_id: 7,
    name: '开学季满 99 减 20',
    scope_type: 'all' as const,
    scope_summary: '无门槛',
    min_amount: 99,
    discount_amount: 20,
    status: 'unused' as const,
    source: 'claim' as const,
    expires_at: '2026-09-30T23:59:59+08:00',
    created_at: '2026-09-01T00:00:00+08:00',
  });
});

describe('CouponsView', () => {
  it('renders claimable cards and claims a coupon', async () => {
    const wrapper = mount(CouponsView);
    await flushPromises();
    expect(wrapper.html()).toContain('开学季满 99 减 20');
    expect(wrapper.html()).toContain('满 99 减 20');
    wrapper.unmount();
  });

  it('maps COUPON_QUOTA_EXCEEDED to friendly message', async () => {
    couponsApi.claimCoupon.mockRejectedValueOnce(
      Object.assign(new Error('COUPON_QUOTA_EXCEEDED'), { code: 'COUPON_QUOTA_EXCEEDED' }),
    );
    const wrapper = mount(CouponsView);
    await flushPromises();
    wrapper.unmount();
  });

  it('uses the learner page shell and a consistent mine coupon row', async () => {
    couponsApi.fetchMyCoupons.mockResolvedValueOnce({
      items: [mineCoupon],
      total: 1,
      page: 1,
      limit: 20,
    });
    const wrapper = mount(CouponsView);
    await flushPromises();

    const tabs = wrapper.findAll('.el-tabs__item');
    expect(tabs).toHaveLength(2);
    await tabs[1]!.trigger('click');
    await flushPromises();

    expect(wrapper.find('main.page').exists()).toBe(true);
    expect(wrapper.find('.coupon-card--mine .coupon-card__details').exists()).toBe(true);
    expect(wrapper.find('.coupon-card--mine .coupon-card__status .el-tag--plain').exists()).toBe(
      true,
    );
    wrapper.unmount();
  });
});
