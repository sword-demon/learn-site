import {
  AdminCourseFeedbackDetailDTO,
  AdminCourseFeedbackListDTO,
  ApiResponse,
  UpdateCourseFeedbackStatusInput,
  type AdminCourseFeedbackDetailDTO as AdminCourseFeedbackDetail,
  type AdminCourseFeedbackListDTO as AdminCourseFeedbackList,
  type CourseFeedbackStatus,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { AdminCourseFeedbackDetail, AdminCourseFeedbackList, CourseFeedbackStatus };

export interface CourseFeedbackListParams {
  status?: CourseFeedbackStatus;
  page?: number;
  limit?: number;
}

export async function listFeedback(
  courseId: number,
  params: CourseFeedbackListParams = {},
): Promise<AdminCourseFeedbackList> {
  const { data } = await http.get(`/courses/${courseId}/feedback`, { params });
  const parsed = ApiResponse(AdminCourseFeedbackListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getFeedback(
  courseId: number,
  feedbackId: number,
): Promise<AdminCourseFeedbackDetail> {
  const { data } = await http.get(`/courses/${courseId}/feedback/${feedbackId}`);
  const parsed = ApiResponse(AdminCourseFeedbackDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function updateFeedbackStatus(
  courseId: number,
  feedbackId: number,
  status: CourseFeedbackStatus,
): Promise<AdminCourseFeedbackDetail> {
  const body = UpdateCourseFeedbackStatusInput.parse({ status });
  const { data } = await http.patch(`/courses/${courseId}/feedback/${feedbackId}`, body);
  const parsed = ApiResponse(AdminCourseFeedbackDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.message), { code: parsed.error.code });
  }
  return parsed.data;
}
