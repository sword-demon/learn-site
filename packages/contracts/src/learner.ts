import { z } from 'zod'

export const LearnerAccountDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  display_name: z.string(),
  department_id: z.number().int().nullable(),
  department_name: z.string(),
  status: z.enum(['active', 'disabled']),
  must_change_password: z.boolean(),
  last_login_at: z.string().nullable(),
  created_at: z.string(),
  course_count: z.number().int().nonnegative(),
  completed_course_count: z.number().int().nonnegative(),
  successful_order_count: z.number().int().nonnegative(),
  total_paid_amount: z.number().nonnegative(),
})
export type LearnerAccountDTO = z.infer<typeof LearnerAccountDTO>

export const LearnerListDTO = z.object({
  items: z.array(LearnerAccountDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type LearnerListDTO = z.infer<typeof LearnerListDTO>

export const LearnerKickResultDTO = z.object({
  revoked: z.number().int(),
})
export type LearnerKickResultDTO = z.infer<typeof LearnerKickResultDTO>

export const LearnerPasswordResetDTO = z.object({
  reset: z.literal(true),
})
export type LearnerPasswordResetDTO = z.infer<typeof LearnerPasswordResetDTO>

export const LearnerSummaryDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  display_name: z.string(),
  status: z.enum(['active', 'disabled']),
})
export type LearnerSummaryDTO = z.infer<typeof LearnerSummaryDTO>

export const LearnerCourseProgressDTO = z.object({
  course_id: z.number().int(),
  course_title: z.string(),
  source: z.enum(['free', 'purchase', 'activation_code']),
  entitlement_status: z.enum(['active', 'revoked']),
  progress_percent: z.number().int().min(0).max(100),
  learning_status: z.enum(['not_started', 'in_progress', 'completed']),
  last_learning_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  enrolled_at: z.string(),
})
export type LearnerCourseProgressDTO = z.infer<typeof LearnerCourseProgressDTO>

export const LearnerCourseProgressListDTO = z.object({
  learner: LearnerSummaryDTO,
  items: z.array(LearnerCourseProgressDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type LearnerCourseProgressListDTO = z.infer<typeof LearnerCourseProgressListDTO>

export const LearnerLessonRecordDTO = z.object({
  course_id: z.number().int(),
  course_title: z.string(),
  lesson_id: z.number().int(),
  lesson_title: z.string(),
  opened_at: z.string().nullable(),
  completed: z.boolean(),
  completed_at: z.string().nullable(),
  updated_at: z.string(),
})
export type LearnerLessonRecordDTO = z.infer<typeof LearnerLessonRecordDTO>

export const LearnerLessonRecordListDTO = z.object({
  learner: LearnerSummaryDTO,
  items: z.array(LearnerLessonRecordDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type LearnerLessonRecordListDTO = z.infer<typeof LearnerLessonRecordListDTO>
