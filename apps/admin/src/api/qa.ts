import http from './http';
import type {
  AdminInboxDTO,
  AnswerInput,
  QuestionFilterOptionsDTO,
  QuestionStatus,
  QuestionThreadDTO,
} from '@learn-site/contracts';

/**
 * Admin Q&A inbox API (Phase 11 / US4 — T068).
 * Endpoint shapes live in apps/api/app/controller/admin/QuestionController.php
 * and packages/contracts/src/qa.ts. Errors propagate via axios so views
 * can branch on the response body `{ error: { code, message } }`.
 */

export interface InboxParams {
  status?: QuestionStatus;
  course_id?: number;
  lesson_id?: number;
  page?: number;
  limit?: number;
}

export async function fetchInbox(params: InboxParams = {}): Promise<AdminInboxDTO> {
  const { data } = await http.get<AdminInboxDTO>('/questions', { params });
  return data;
}

export async function fetchFilterOptions(courseId?: number): Promise<QuestionFilterOptionsDTO> {
  const { data } = await http.get<QuestionFilterOptionsDTO>('/questions/filter-options', {
    params: courseId === undefined ? {} : { course_id: courseId },
  });
  return data;
}

export async function fetchThread(id: number): Promise<QuestionThreadDTO> {
  const { data } = await http.get<QuestionThreadDTO>(`/questions/${id}`);
  return data;
}

export async function answerQuestion(id: number, input: AnswerInput): Promise<QuestionThreadDTO> {
  const { data } = await http.post<QuestionThreadDTO>(`/questions/${id}/answer`, input);
  return data;
}

export async function closeQuestion(id: number): Promise<QuestionThreadDTO> {
  const { data } = await http.post<QuestionThreadDTO>(`/questions/${id}/close`);
  return data;
}
