import { AdminCheckinDetailDTO, AdminCheckinListDTO, ApiResponse } from '@learn-site/contracts';
import { http } from '@/api/http';

export type CheckinListParams = {
  page?: number;
  limit?: number;
  learner_id?: number;
  date_from?: string;
  date_to?: string;
};

export async function listCheckins(params: CheckinListParams = {}): Promise<AdminCheckinListDTO> {
  const { data } = await http.get('/checkins', { params });
  const parsed = ApiResponse(AdminCheckinListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getCheckin(id: number): Promise<AdminCheckinDetailDTO> {
  const { data } = await http.get(`/checkins/${id}`);
  const parsed = ApiResponse(AdminCheckinDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function deleteCheckin(id: number): Promise<void> {
  await http.delete(`/checkins/${id}`);
}
