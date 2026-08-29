import { z } from 'zod'

export const ScheduledTaskHandlerStatus = z.enum(['available', 'unavailable'])
export type ScheduledTaskHandlerStatus = z.infer<typeof ScheduledTaskHandlerStatus>

export const ScheduledTaskLastRunStatus = z.enum(['success', 'failed', 'skipped'])
export type ScheduledTaskLastRunStatus = z.infer<typeof ScheduledTaskLastRunStatus>

export const ScheduledTaskTriggerType = z.enum(['schedule', 'manual'])
export type ScheduledTaskTriggerType = z.infer<typeof ScheduledTaskTriggerType>

export const ScheduledTaskRunStatus = z.enum(['success', 'failed', 'skipped'])
export type ScheduledTaskRunStatus = z.infer<typeof ScheduledTaskRunStatus>

export const AdminScheduledTaskDTO = z.object({
  id: z.number().int(),
  handler_code: z.string(),
  name: z.string(),
  description: z.string().nullable(),
  schedule_expression: z.string(),
  enabled: z.boolean(),
  params: z.record(z.string(), z.unknown()).nullable(),
  handler_status: ScheduledTaskHandlerStatus,
  last_run_at: z.string().nullable(),
  last_run_status: ScheduledTaskLastRunStatus.nullable(),
  next_run_at: z.string().nullable(),
  updated_at: z.string(),
})
export type AdminScheduledTaskDTO = z.infer<typeof AdminScheduledTaskDTO>

export const ValidateExpressionResultDTO = z.object({
  valid: z.boolean(),
  next_run_at: z.string().nullable(),
  error: z.string().nullable(),
})
export type ValidateExpressionResultDTO = z.infer<typeof ValidateExpressionResultDTO>

export const UpdateScheduledTaskBody = z.object({
  schedule_expression: z.string().min(1).max(128).optional(),
  enabled: z.boolean().optional(),
  params: z.record(z.string(), z.unknown()).optional(),
})
export type UpdateScheduledTaskBody = z.infer<typeof UpdateScheduledTaskBody>

export const AdminScheduledTaskRunListItemDTO = z.object({
  id: z.number().int(),
  task_id: z.number().int(),
  task_name: z.string(),
  trigger_type: ScheduledTaskTriggerType,
  status: ScheduledTaskRunStatus,
  started_at: z.string(),
  finished_at: z.string().nullable(),
  duration_ms: z.number().int().nonnegative().nullable(),
  error_message: z.string().nullable(),
  actor_staff_id: z.number().int().nullable(),
  actor_login: z.string().nullable(),
})
export type AdminScheduledTaskRunListItemDTO = z.infer<typeof AdminScheduledTaskRunListItemDTO>

export const AdminScheduledTaskRunDetailDTO = AdminScheduledTaskRunListItemDTO.extend({
  context: z.record(z.string(), z.unknown()).nullable(),
})
export type AdminScheduledTaskRunDetailDTO = z.infer<typeof AdminScheduledTaskRunDetailDTO>

export const AdminScheduledTaskRunListDTO = z.object({
  items: z.array(AdminScheduledTaskRunListItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  per_page: z.number().int().positive(),
})
export type AdminScheduledTaskRunListDTO = z.infer<typeof AdminScheduledTaskRunListDTO>

export const ScheduledTaskRunSummaryDTO = z.object({
  run_id: z.number().int(),
  task_id: z.number().int(),
  trigger_type: ScheduledTaskTriggerType,
  status: ScheduledTaskRunStatus,
  started_at: z.string(),
  finished_at: z.string().nullable(),
  duration_ms: z.number().int().nonnegative().nullable(),
})
export type ScheduledTaskRunSummaryDTO = z.infer<typeof ScheduledTaskRunSummaryDTO>

export const ValidateExpressionBody = z.object({
  schedule_expression: z.string().min(1).max(128),
})
export type ValidateExpressionBody = z.infer<typeof ValidateExpressionBody>
