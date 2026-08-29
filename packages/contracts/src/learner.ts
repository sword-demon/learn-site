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
