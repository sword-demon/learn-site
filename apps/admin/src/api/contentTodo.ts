import {
  ContentTodoApproveRequestSchema,
  ContentTodoCandidateEditRequestSchema,
  ContentTodoCandidateRequestSchema,
  ContentTodoCloseRequestSchema,
  ContentTodoRejectRequestSchema,
  ContentTodoDetailSchema,
  ContentTodoListRequestSchema,
  ContentTodoListResponseSchema,
  ContentTodoResponseRequestSchema,
  ContentTodoTriageRequestSchema,
} from '@contracts/contentTodo';
import { ApiOk } from '@contracts/envelope';
import type {
  ContentTodoApproveRequest,
  ContentTodoCandidateEditRequest,
  ContentTodoCandidateRequest,
  ContentTodoCloseRequest,
  ContentTodoDetail,
  ContentTodoRejectRequest,
  ContentTodoListRequest,
  ContentTodoListResponse,
  ContentTodoResponseRequest,
  ContentTodoTriageRequest,
} from '@contracts/contentTodo';
import { http } from '@/api/http';

const listEnvelope = ApiOk(ContentTodoListResponseSchema);
const detailEnvelope = ApiOk(ContentTodoDetailSchema);

export async function fetchContentTodos(
  params: ContentTodoListRequest,
): Promise<ContentTodoListResponse> {
  const response = await http.get<unknown>('/ops-inbox/content-todos', {
    params: ContentTodoListRequestSchema.parse(params),
  });
  return listEnvelope.parse(response.data).data;
}

export async function fetchContentTodo(id: number): Promise<ContentTodoDetail> {
  const response = await http.get<unknown>(`/ops-inbox/content-todos/${id}`);
  return detailEnvelope.parse(response.data).data;
}

export async function triageContentTodo(
  id: number,
  body: ContentTodoTriageRequest,
): Promise<ContentTodoDetail> {
  const response = await http.patch<unknown>(
    `/ops-inbox/content-todos/${id}`,
    ContentTodoTriageRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function respondContentTodo(
  id: number,
  body: ContentTodoResponseRequest,
): Promise<ContentTodoDetail> {
  const response = await http.post<unknown>(
    `/ops-inbox/content-todos/${id}/respond`,
    ContentTodoResponseRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function generateContentTodoCandidate(
  id: number,
  body: ContentTodoCandidateRequest = {},
): Promise<ContentTodoDetail> {
  const response = await http.post<unknown>(
    `/ops-inbox/content-todos/${id}/candidates`,
    ContentTodoCandidateRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function editContentTodoCandidate(
  todoId: number,
  candidateId: number,
  body: ContentTodoCandidateEditRequest,
): Promise<ContentTodoDetail> {
  const response = await http.patch<unknown>(
    `/ops-inbox/content-todos/${todoId}/candidates/${candidateId}`,
    ContentTodoCandidateEditRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function approveContentTodoCandidate(
  todoId: number,
  candidateId: number,
  body: ContentTodoApproveRequest = {},
): Promise<ContentTodoDetail> {
  const response = await http.post<unknown>(
    `/ops-inbox/content-todos/${todoId}/candidates/${candidateId}/approve`,
    ContentTodoApproveRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function rejectContentTodoCandidate(
  todoId: number,
  candidateId: number,
  body: ContentTodoRejectRequest,
): Promise<ContentTodoDetail> {
  const response = await http.post<unknown>(
    `/ops-inbox/content-todos/${todoId}/candidates/${candidateId}/reject`,
    ContentTodoRejectRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}

export async function closeContentTodo(
  id: number,
  body: ContentTodoCloseRequest,
): Promise<ContentTodoDetail> {
  const response = await http.post<unknown>(
    `/ops-inbox/content-todos/${id}/close`,
    ContentTodoCloseRequestSchema.parse(body),
  );
  return detailEnvelope.parse(response.data).data;
}
