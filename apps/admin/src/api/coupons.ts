import {
  AdminCouponCampaignDTO,
  AdminCouponCampaignListDTO,
  AdminCouponRedemptionListDTO,
  ApiResponse,
  CreateCouponInput,
  GrantCouponInput,
  GrantCouponResultDTO,
  LearnerCouponDTO,
  PatchCouponInput,
  type AdminCouponCampaignDTO as AdminCoupon,
  type AdminCouponCampaignListDTO as AdminCouponList,
  type AdminCouponRedemptionListDTO as AdminCouponRedemptionList,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { AdminCoupon, AdminCouponList, AdminCouponRedemptionList };

export interface CouponListParams {
  page?: number;
  limit?: number;
  scope_type?: 'category' | 'course' | 'all' | '' | undefined;
  status?: 'active' | 'disabled' | 'scheduled' | 'ended' | '' | undefined;
}

export async function listCoupons(params: CouponListParams = {}): Promise<AdminCouponList> {
  const { data } = await http.get('/coupons', { params });
  const parsed = ApiResponse(AdminCouponCampaignListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getCoupon(id: number): Promise<AdminCoupon> {
  const { data } = await http.get(`/coupons/${id}`);
  const parsed = ApiResponse(AdminCouponCampaignDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function createCoupon(input: CreateCouponInput): Promise<AdminCoupon> {
  const body = CreateCouponInput.parse(input);
  const { data } = await http.post('/coupons', body);
  const parsed = ApiResponse(AdminCouponCampaignDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function patchCoupon(id: number, input: PatchCouponInput): Promise<AdminCoupon> {
  const body = PatchCouponInput.parse(input);
  const { data } = await http.patch(`/coupons/${id}`, body);
  const parsed = ApiResponse(AdminCouponCampaignDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function disableCoupon(id: number): Promise<void> {
  await http.post(`/coupons/${id}/disable`);
}

export async function grantCoupon(
  id: number,
  input: GrantCouponInput,
): Promise<{ granted: number; skipped: number; items: LearnerCouponDTO[] }> {
  const body = GrantCouponInput.parse(input);
  const { data } = await http.post(`/coupons/${id}/grants`, body);
  const parsed = ApiResponse(GrantCouponResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export interface RedemptionListParams {
  page?: number;
  limit?: number;
  campaign_id?: number | null;
  learner_id?: number | null;
  from?: string | null;
  to?: string | null;
}

export async function listRedemptions(
  params: RedemptionListParams = {},
): Promise<AdminCouponRedemptionList> {
  const { data } = await http.get('/coupon-redemptions', { params });
  const parsed = ApiResponse(AdminCouponRedemptionListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
