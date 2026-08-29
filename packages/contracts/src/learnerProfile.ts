import { z } from 'zod'

export const LearnerProfileDTO = z.object({
  account_id: z.number().int().positive(),
  phone: z.string().regex(/^1[3-9]\d{9}$/),
  nickname: z.string().min(1).max(32).nullable(),
  avatar_url: z.string().nullable(),
  show_on_course: z.boolean(),
  status: z.enum(['active', 'disabled']),
  created_at: z.string(),
})
export type LearnerProfileDTO = z.infer<typeof LearnerProfileDTO>

export const LearnerProfileUpdateInput = z
  .object({
    nickname: z.string().trim().min(1).max(32).nullable().optional(),
    show_on_course: z.boolean().optional(),
  })
  .refine((value) => Object.keys(value).length > 0, 'PROFILE_EMPTY')
export type LearnerProfileUpdateInput = z.infer<typeof LearnerProfileUpdateInput>
