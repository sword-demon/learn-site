import {
  AdminBannerDTO,
  AdminBannerListDTO,
  ApiResponse,
  CreateBannerInput,
  UpdateBannerInput,
  type AdminBannerDTO as AdminBanner,
  type AdminBannerListDTO as AdminBannerList,
} from '@learn-site/contracts';
import { ZodError } from 'zod';
import { http } from '@/api/http';

export type { AdminBanner, AdminBannerList };

export interface BannerListParams {
  page?: number;
  limit?: number;
  is_enabled?: boolean;
}

export async function listBanners(params: BannerListParams = {}): Promise<AdminBannerList> {
  const { data } = await http.get('/banners', { params });
  const parsed = ApiResponse(AdminBannerListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getBanner(id: number): Promise<AdminBanner> {
  const { data } = await http.get(`/banners/${id}`);
  const parsed = ApiResponse(AdminBannerDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

function parseBannerInput<T>(schema: { parse: (input: unknown) => T }, input: unknown): T {
  try {
    return schema.parse(input);
  } catch (error) {
    if (
      error instanceof ZodError &&
      error.issues.some((issue) => issue.path[0] === 'image_url' || issue.path[0] === 'image_key')
    ) {
      throw new Error('轮播图片无效，请重新上传');
    }
    throw error;
  }
}

export async function createBanner(input: CreateBannerInput): Promise<AdminBanner> {
  const body = parseBannerInput(CreateBannerInput, input);
  const { data } = await http.post('/banners', body);
  const parsed = ApiResponse(AdminBannerDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function updateBanner(id: number, input: UpdateBannerInput): Promise<AdminBanner> {
  const body = parseBannerInput(UpdateBannerInput, input);
  const { data } = await http.patch(`/banners/${id}`, body);
  const parsed = ApiResponse(AdminBannerDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function deleteBanner(id: number): Promise<void> {
  await http.delete(`/banners/${id}`);
}
