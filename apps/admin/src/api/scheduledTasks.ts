import { z } from 'zod';
import {
  AdminScheduledTaskDTO,
  AdminScheduledTaskRunDetailDTO,
  AdminScheduledTaskRunListDTO,
  ApiResponse,
  ScheduledTaskRunSummaryDTO,
  UpdateScheduledTaskBody,
  ValidateExpressionBody,
  ValidateExpressionResultDTO,
  type AdminScheduledTaskDTO as AdminScheduledTask,
  type AdminScheduledTaskRunDetailDTO as AdminScheduledTaskRunDetail,
  type AdminScheduledTaskRunListDTO as AdminScheduledTaskRunList,
  type ScheduledTaskRunSummaryDTO as ScheduledTaskRunSummary,
  type ValidateExpressionResultDTO as ValidateExpressionResult,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type {
  AdminScheduledTask,
  AdminScheduledTaskRunDetail,
  AdminScheduledTaskRunList,
  ScheduledTaskRunSummary,
  ValidateExpressionResult,
};

export interface ScheduledTaskRunListParams {
  task_id?: number;
  status?: 'success' | 'failed' | 'skipped' | '';
  trigger_type?: 'schedule' | 'manual' | '';
  started_from?: string;
  started_to?: string;
  page?: number;
  per_page?: number;
}

export async function listScheduledTasks(): Promise<AdminScheduledTask[]> {
  const { data } = await http.get('/scheduled-tasks');
  const parsed = ApiResponse(z.array(AdminScheduledTaskDTO)).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getScheduledTask(id: number): Promise<AdminScheduledTask> {
  const { data } = await http.get(`/scheduled-tasks/${id}`);
  const parsed = ApiResponse(AdminScheduledTaskDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function validateScheduleExpression(
  schedule_expression: string,
): Promise<ValidateExpressionResult> {
  const body = ValidateExpressionBody.parse({ schedule_expression });
  const { data } = await http.post('/scheduled-tasks/validate-expression', body);
  const parsed = ApiResponse(ValidateExpressionResultDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function updateScheduledTask(
  id: number,
  payload: UpdateScheduledTaskBody,
): Promise<AdminScheduledTask> {
  const body = UpdateScheduledTaskBody.parse(payload);
  const { data } = await http.patch(`/scheduled-tasks/${id}`, body);
  const parsed = ApiResponse(AdminScheduledTaskDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function runScheduledTask(id: number): Promise<ScheduledTaskRunSummary> {
  const { data } = await http.post(`/scheduled-tasks/${id}/run`);
  const parsed = ApiResponse(ScheduledTaskRunSummaryDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function listScheduledTaskRuns(
  params: ScheduledTaskRunListParams = {},
): Promise<AdminScheduledTaskRunList> {
  const { data } = await http.get('/scheduled-tasks/runs', { params });
  const parsed = ApiResponse(AdminScheduledTaskRunListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getScheduledTaskRun(id: number): Promise<AdminScheduledTaskRunDetail> {
  const { data } = await http.get(`/scheduled-tasks/runs/${id}`);
  const parsed = ApiResponse(AdminScheduledTaskRunDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
