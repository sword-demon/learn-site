import { z } from 'zod'

export const CourseStudentDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  nickname: z.string(),
  account_status: z.enum(['active', 'disabled']),
  source: z.enum(['free', 'purchase', 'activation_code']),
  entitlement_status: z.enum(['active', 'revoked']),
  progress_percent: z.number().int().min(0).max(100),
  learning_status: z.enum(['not_started', 'in_progress', 'completed']),
  last_learning_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  enrolled_at: z.string(),
  revoked_at: z.string().nullable(),
  revoked_reason: z.string().nullable(),
  last_login_at: z.string().nullable(),
})
export type CourseStudentDTO = z.infer<typeof CourseStudentDTO>

export const CourseStudentListDTO = z.object({
  items: z.array(CourseStudentDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type CourseStudentListDTO = z.infer<typeof CourseStudentListDTO>

export const CourseStudentRevokeResultDTO = z.object({
  revoked: z.literal(true),
})
export type CourseStudentRevokeResultDTO = z.infer<typeof CourseStudentRevokeResultDTO>

export const CourseStudentResetResultDTO = z.object({
  reset: z.literal(true),
})
export type CourseStudentResetResultDTO = z.infer<typeof CourseStudentResetResultDTO>
