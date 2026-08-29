import { z } from 'zod';
import { ApiResponse } from '@learn-site/contracts';
import {
  CourseStudentDTO,
  CourseStudentListDTO,
  CourseStudentRevokeResultDTO,
  CourseStudentResetResultDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export { CourseStudentDTO, CourseStudentListDTO };
export type {
  CourseStudentDTO as CourseStudentDTOType,
  CourseStudentListDTO as CourseStudentListDTOType,
};

export interface CourseStudentListParams {
  status?: '' | 'active' | 'revoked';
  source?: '' | 'free' | 'purchase';
  learning_status?: '' | 'not_started' | 'in_progress' | 'completed';
  page?: number;
  limit?: number;
}

export async function resetCourseStudentProgress(
  courseId: number,
  accountId: number,
): Promise<z.infer<typeof CourseStudentResetResultDTO>> {
  const { data } = await http.post(`/courses/${courseId}/students/${accountId}/progress/reset`);
  const parsed = ApiResponse(CourseStudentResetResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function listCourseStudents(
  courseId: number,
  params: CourseStudentListParams = {},
): Promise<CourseStudentListDTO> {
  const { data } = await http.get(`/courses/${courseId}/students`, { params });
  const parsed = ApiResponse(CourseStudentListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function revokeCourseStudent(
  courseId: number,
  accountId: number,
  reason?: string,
): Promise<z.infer<typeof CourseStudentRevokeResultDTO>> {
  const { data } = await http.post(
    `/courses/${courseId}/students/${accountId}/revoke`,
    reason ? { reason } : {},
  );
  const parsed = ApiResponse(CourseStudentRevokeResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
