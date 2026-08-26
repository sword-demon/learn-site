import { z } from 'zod'

export const CourseStudentDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  display_name: z.string(),
  department_id: z.number().int().nullable(),
  department_name: z.string(),
  account_status: z.enum(['active', 'disabled']),
  source: z.enum(['free', 'purchase']),
  entitlement_status: z.enum(['active', 'revoked']),
  enrolled_at: z.string(),
  revoked_at: z.string().nullable(),
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
