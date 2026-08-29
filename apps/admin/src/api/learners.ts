import { ApiResponse } from '@learn-site/contracts';
import {
  LearnerAccountDTO,
  LearnerListDTO,
  LearnerKickResultDTO,
  LearnerPasswordResetDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export { LearnerAccountDTO, LearnerListDTO };
export type { LearnerAccountDTO as LearnerAccountDTOType, LearnerListDTO as LearnerListDTOType };

export interface LearnerListParams {
  status?: '' | 'active' | 'disabled';
  department_id?: number | null;
  search?: string;
  page?: number;
  limit?: number;
}

export async function listLearners(params: LearnerListParams = {}): Promise<LearnerListDTO> {
  const { data } = await http.get('/learners', { params });
  const parsed = ApiResponse(LearnerListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function kickLearner(
  accountId: number,
  familyId?: string,
): Promise<LearnerKickResultDTO> {
  const body: Record<string, unknown> = {};
  if (familyId) body.family_id = familyId;
  const { data } = await http.post(`/learners/${accountId}/kick`, body);
  const parsed = ApiResponse(LearnerKickResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function resetLearnerPassword(
  accountId: number,
  newPassword: string,
): Promise<LearnerPasswordResetDTO> {
  const { data } = await http.post(`/learners/${accountId}/password`, {
    new_password: newPassword,
  });
  const parsed = ApiResponse(LearnerPasswordResetDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
