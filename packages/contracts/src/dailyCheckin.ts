import { z } from 'zod'

export const LearnerCheckinDTO = z.object({
  id: z.number().int().positive(),
  checkin_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  plan_html: z.string(),
  checked_in_at: z.string().datetime({ offset: true }),
})
export type LearnerCheckinDTO = z.infer<typeof LearnerCheckinDTO>

export const LearnerTodayCheckinDTO = z.object({
  server_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  checked_in: z.boolean(),
  record: LearnerCheckinDTO.nullable(),
})
export type LearnerTodayCheckinDTO = z.infer<typeof LearnerTodayCheckinDTO>

export const CreateCheckinInput = z.object({
  plan_html: z.string().min(1),
})
export type CreateCheckinInput = z.infer<typeof CreateCheckinInput>

export const LearnerCheckinListDTO = z.object({
  items: z.array(LearnerCheckinDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type LearnerCheckinListDTO = z.infer<typeof LearnerCheckinListDTO>

export const AdminCheckinListItemDTO = z.object({
  id: z.number().int().positive(),
  learner_id: z.number().int().positive(),
  learner_display_name: z.string().nullable(),
  learner_phone_masked: z.string(),
  checkin_date: z.string().regex(/^\d{4}-\d{2}-\d{2}$/),
  plan_summary: z.string(),
  checked_in_at: z.string().datetime({ offset: true }),
})
export type AdminCheckinListItemDTO = z.infer<typeof AdminCheckinListItemDTO>

export const AdminCheckinDetailDTO = AdminCheckinListItemDTO.extend({
  plan_html: z.string(),
})
export type AdminCheckinDetailDTO = z.infer<typeof AdminCheckinDetailDTO>

export const AdminCheckinListDTO = z.object({
  items: z.array(AdminCheckinListItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type AdminCheckinListDTO = z.infer<typeof AdminCheckinListDTO>
