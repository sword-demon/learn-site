import { z } from 'zod';
import {
  ApiResponse,
  AdminCommissionByOrderDTO,
  CommissionRecordDTO,
  CommissionVoidInput,
  DistributionConfigDTO,
  DistributionConfigUpdateInput,
  DistributionCourseOverrideDTO,
  DistributionCourseOverrideUpsertInput,
  DistributionAuditListDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

const OverridePage = z.object({
  items: z.array(DistributionCourseOverrideDTO),
  total: z.number(),
  page: z.number(),
  limit: z.number(),
});

const AdminCommissionPage = z.object({
  items: z.array(CommissionRecordDTO),
  total: z.number(),
  page: z.number(),
  limit: z.number(),
});

export interface AdminCommissionListParams {
  page?: number;
  limit?: number;
  order_id?: number;
  learner_id?: number;
  course_id?: number;
  status?: string;
}

export interface DistributionAuditListParams {
  page?: number;
  limit?: number;
  action?: string;
  actor_type?: string;
}

function httpErrorPayload(error: unknown): unknown {
  if (typeof error !== 'object' || error === null || !('response' in error)) return undefined;
  const response = (error as { response?: { data?: unknown } }).response;
  return response?.data;
}

function rethrowHttp(error: unknown): never {
  const payload = httpErrorPayload(error);
  if (payload !== undefined) {
    const parsed = ApiResponse(z.unknown()).safeParse(payload);
    if (parsed.success && parsed.data.ok === false) {
      throw Object.assign(new Error(parsed.data.error.message || parsed.data.error.code), {
        code: parsed.data.error.code,
      });
    }
  }
  throw error;
}

export async function fetchDistributionConfig(): Promise<DistributionConfigDTO> {
  try {
    const { data } = await http.get('/distribution/config');
    const parsed = ApiResponse(DistributionConfigDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function saveDistributionConfig(
  input: DistributionConfigUpdateInput,
): Promise<DistributionConfigDTO> {
  const body = DistributionConfigUpdateInput.parse(input);
  try {
    const { data } = await http.put('/distribution/config', body);
    const parsed = ApiResponse(DistributionConfigDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function fetchCourseOverrides(page = 1, limit = 20) {
  try {
    const { data } = await http.get('/distribution/course-overrides', { params: { page, limit } });
    const parsed = ApiResponse(OverridePage).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function saveCourseOverride(
  courseId: number,
  input: z.input<typeof DistributionCourseOverrideUpsertInput> | { enabled: boolean },
): Promise<DistributionCourseOverrideDTO> {
  const body = DistributionCourseOverrideUpsertInput.partial()
    .required({ enabled: true })
    .parse(input);
  try {
    const { data } = await http.put(`/distribution/course-overrides/${courseId}`, body);
    const parsed = ApiResponse(DistributionCourseOverrideDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function fetchReconcileByOrder(
  orderId: number,
): Promise<AdminCommissionByOrderDTO | null> {
  try {
    const { data } = await http.get(`/distribution/reconcile/by-order/${orderId}`);
    const parsed = ApiResponse(AdminCommissionByOrderDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    const code = error instanceof Error ? (error as Error & { code?: string }).code : undefined;
    if (code === 'NOT_FOUND') return null;
    try {
      rethrowHttp(error);
    } catch (next) {
      const nextCode = next instanceof Error ? (next as Error & { code?: string }).code : undefined;
      if (nextCode === 'NOT_FOUND') return null;
      throw next;
    }
  }
}

export async function fetchCommissions(params: AdminCommissionListParams = {}) {
  try {
    const { data } = await http.get('/distribution/commissions', { params });
    const parsed = ApiResponse(AdminCommissionPage).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function voidCommission(id: number, reason: string): Promise<CommissionRecordDTO> {
  const body = CommissionVoidInput.parse({ reason });
  try {
    const { data } = await http.post(`/distribution/commissions/${id}/void`, body);
    const parsed = ApiResponse(CommissionRecordDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function fetchAudit(params: DistributionAuditListParams = {}) {
  try {
    const { data } = await http.get('/distribution/audit', { params });
    const parsed = ApiResponse(DistributionAuditListDTO).parse(data);
    if (!parsed.ok) throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
    return parsed.data;
  } catch (error) {
    rethrowHttp(error);
  }
}

export async function exportCommissionsCsv(
  params: AdminCommissionListParams = {},
): Promise<string> {
  try {
    const { data } = await http.get('/distribution/commissions/export', {
      params,
      responseType: 'text',
    });
    return String(data);
  } catch (error) {
    rethrowHttp(error);
  }
}
