import http from './http';
import type {
  AdminInboxDTO,
  AnswerInput,
  QuestionFilterOptionsDTO,
  QuestionStatus,
  QuestionThreadDTO,
} from '@learn-site/contracts';
import {
  AdminInboxDTO as AdminInboxSchema,
  ApiOk,
  QuestionFilterOptionsDTO as QuestionFilterOptionsSchema,
  QuestionThreadDTO as QuestionThreadSchema,
} from '@learn-site/contracts';

/**
 * Admin Q&A inbox API (Phase 11 / US4 — T068).
 * Endpoint shapes live in apps/api/app/controller/admin/QuestionController.php
 * and packages/contracts/src/qa.ts. Every successful response is unwrapped
 * from the canonical `{ ok: true, data }` envelope at this boundary.
 */

export interface InboxParams {
  status?: QuestionStatus;
  course_id?: number;
  lesson_id?: number;
  page?: number;
  limit?: number;
}

const AdminInboxEnvelope = ApiOk(AdminInboxSchema);
const QuestionFilterOptionsEnvelope = ApiOk(QuestionFilterOptionsSchema);
const QuestionThreadEnvelope = ApiOk(QuestionThreadSchema);

export async function fetchInbox(params: InboxParams = {}): Promise<AdminInboxDTO> {
  const { data } = await http.get<unknown>('/questions', { params });
  return AdminInboxEnvelope.parse(data).data;
}

export async function fetchFilterOptions(courseId?: number): Promise<QuestionFilterOptionsDTO> {
  const { data } = await http.get<unknown>('/questions/filter-options', {
    params: courseId === undefined ? {} : { course_id: courseId },
  });
  return QuestionFilterOptionsEnvelope.parse(data).data;
}

export async function fetchThread(id: number): Promise<QuestionThreadDTO> {
  const { data } = await http.get<unknown>(`/questions/${id}`);
  return QuestionThreadEnvelope.parse(data).data;
}

export async function answerQuestion(id: number, input: AnswerInput): Promise<QuestionThreadDTO> {
  const { data } = await http.post<unknown>(`/questions/${id}/answer`, input);
  return QuestionThreadEnvelope.parse(data).data;
}

export async function closeQuestion(id: number): Promise<QuestionThreadDTO> {
  const { data } = await http.post<unknown>(`/questions/${id}/close`);
  return QuestionThreadEnvelope.parse(data).data;
}
