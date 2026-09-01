import {
  CheckoutCouponsDTO,
  CheckoutCouponOptionDTO,
  CouponPublicDTO,
  CouponPublicListDTO,
  LearnerCouponDTO,
  LearnerCouponListDTO,
  ApiResponse,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export interface MyCouponsParams {
  page?: number;
  limit?: number;
  status?: 'unused' | 'used' | 'expired' | '' | undefined;
}

export async function fetchClaimableCoupons(): Promise<CouponPublicDTO[]> {
  const { data } = await http.get('/coupons/claimable');
  const parsed = ApiResponse(CouponPublicListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data.items;
}

export async function claimCoupon(campaignId: number): Promise<LearnerCouponDTO> {
  const { data } = await http.post(`/coupons/${campaignId}/claim`);
  const parsed = ApiResponse(LearnerCouponDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function fetchMyCoupons(params: MyCouponsParams = {}): Promise<LearnerCouponListDTO> {
  const { data } = await http.get('/my/coupons', { params });
  const parsed = ApiResponse(LearnerCouponListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export interface CheckoutCouponsResult {
  base_price: number;
  list_price: number;
  sale_price: number;
  items: CheckoutCouponOptionDTO[];
}

export async function fetchCheckoutCoupons(courseId: number): Promise<CheckoutCouponsResult> {
  const { data } = await http.get(`/courses/${courseId}/checkout-coupons`);
  const parsed = ApiResponse(CheckoutCouponsDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
