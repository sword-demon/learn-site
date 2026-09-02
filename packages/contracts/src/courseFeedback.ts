import { z } from 'zod'

export const CourseFeedbackStatus = z.enum(['pending', 'processed'])
export type CourseFeedbackStatus = z.infer<typeof CourseFeedbackStatus>

export const CourseFeedbackLearnerDTO = z.object({
  account_id: z.number().int().positive(),
  nickname: z.string(),
})
export type CourseFeedbackLearnerDTO = z.infer<typeof CourseFeedbackLearnerDTO>

export const SubmitCourseFeedbackInput = z.object({
  body_html: z.string().min(1).max(20_000),
})
export type SubmitCourseFeedbackInput = z.infer<typeof SubmitCourseFeedbackInput>

export const CourseFeedbackCreatedDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  status: z.literal('pending'),
  created_at: z.string(),
})
export type CourseFeedbackCreatedDTO = z.infer<typeof CourseFeedbackCreatedDTO>

export const AdminCourseFeedbackListItemDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  learner: CourseFeedbackLearnerDTO,
  body_excerpt: z.string().max(80),
  status: CourseFeedbackStatus,
  created_at: z.string(),
  processed_at: z.string().nullable(),
})
export type AdminCourseFeedbackListItemDTO = z.infer<typeof AdminCourseFeedbackListItemDTO>

export const AdminCourseFeedbackListDTO = z.object({
  items: z.array(AdminCourseFeedbackListItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type AdminCourseFeedbackListDTO = z.infer<typeof AdminCourseFeedbackListDTO>

export const AdminCourseFeedbackDetailDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  learner: CourseFeedbackLearnerDTO,
  body_html: z.string(),
  status: CourseFeedbackStatus,
  created_at: z.string(),
  processed_at: z.string().nullable(),
  processed_by_staff_id: z.number().int().positive().nullable(),
})
export type AdminCourseFeedbackDetailDTO = z.infer<typeof AdminCourseFeedbackDetailDTO>

export const UpdateCourseFeedbackStatusInput = z.object({
  status: CourseFeedbackStatus,
})
export type UpdateCourseFeedbackStatusInput = z.infer<typeof UpdateCourseFeedbackStatusInput>
