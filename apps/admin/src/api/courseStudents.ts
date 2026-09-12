import { z } from 'zod';
import { ApiResponse } from '@learn-site/contracts';
import {
  CourseStudentDTO,
  CourseStudentListDTO,
  CourseStudentRevokeResultDTO,
  CourseStudentResetResultDTO,
  CourseStartQueueListDTO,
  CourseStartReminderResultDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export {
  CourseStudentDTO,
  CourseStudentListDTO,
  CourseStartQueueListDTO,
  CourseStartReminderResultDTO,
};
export type {
  CourseStudentDTO as CourseStudentDTOType,
  CourseStudentListDTO as CourseStudentListDTOType,
  CourseStartQueueListDTO as CourseStartQueueListDTOType,
  CourseStartReminderResultDTO as CourseStartReminderResultDTOType,
};

export interface CourseStudentListParams {
  status?: '' | 'active' | 'revoked';
  source?: '' | 'free' | 'purchase' | 'activation_code';
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

export interface CourseStartQueueParams {
  source?: '' | 'free' | 'purchase' | 'activation_code';
  startup_state?: '' | 'never_opened' | 'opened_zero_progress';
  sort?: 'idle_hours' | 'entitled_at' | 'source';
  order?: 'asc' | 'desc';
  page?: number;
  limit?: number;
}

export async function listCourseStartQueue(
  courseId: number,
  params: CourseStartQueueParams = {},
): Promise<CourseStartQueueListDTO> {
  const { data } = await http.get(`/courses/${courseId}/start-queue`, { params });
  const parsed = ApiResponse(CourseStartQueueListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function sendCourseStartReminders(
  courseId: number,
  learnerIds: number[],
): Promise<CourseStartReminderResultDTO> {
  const { data } = await http.post(`/courses/${courseId}/start-queue/reminders`, {
    learner_ids: learnerIds,
  });
  const parsed = ApiResponse(CourseStartReminderResultDTO).parse(data);
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
