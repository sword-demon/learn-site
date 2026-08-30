import { ApiResponse } from '@learn-site/contracts';
import {
  LearnerAccountDTO,
  LearnerCourseProgressListDTO,
  LearnerKickResultDTO,
  LearnerLessonRecordListDTO,
  LearnerListDTO,
  LearnerPasswordResetDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

function parseLearnerApiData<T>(schema: Parameters<typeof ApiResponse>[0], data: unknown): T {
  const parsed = ApiResponse(schema).safeParse(data);
  if (!parsed.success) {
    throw new Error('接口响应格式异常，请执行 make rebuild-api 后重试');
  }
  if (!parsed.data.ok) {
    throw Object.assign(new Error(parsed.data.error.code), { code: parsed.data.error.code });
  }
  return parsed.data.data as T;
}

export { LearnerAccountDTO, LearnerListDTO };
export type {
  LearnerAccountDTO as LearnerAccountDTOType,
  LearnerCourseProgressListDTO as LearnerCourseProgressListDTOType,
  LearnerLessonRecordListDTO as LearnerLessonRecordListDTOType,
  LearnerListDTO as LearnerListDTOType,
};

export interface LearnerListParams {
  status?: '' | 'active' | 'disabled';
  search?: string;
  page?: number;
  limit?: number;
}

export interface LearnerDetailListParams {
  page?: number;
  limit?: number;
}

export async function listLearners(params: LearnerListParams = {}): Promise<LearnerListDTO> {
  const { data } = await http.get('/learners', { params });
  return parseLearnerApiData<LearnerListDTO>(LearnerListDTO, data);
}

export async function listLearnerCourseProgress(
  accountId: number,
  params: LearnerDetailListParams = {},
): Promise<LearnerCourseProgressListDTO> {
  const { data } = await http.get(`/learners/${accountId}/learning-progress`, { params });
  return parseLearnerApiData<LearnerCourseProgressListDTO>(LearnerCourseProgressListDTO, data);
}

export async function listLearnerLessonRecords(
  accountId: number,
  params: LearnerDetailListParams = {},
): Promise<LearnerLessonRecordListDTO> {
  const { data } = await http.get(`/learners/${accountId}/learning-records`, { params });
  return parseLearnerApiData<LearnerLessonRecordListDTO>(LearnerLessonRecordListDTO, data);
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
