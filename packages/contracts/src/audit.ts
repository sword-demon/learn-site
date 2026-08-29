import { z } from 'zod'

export const ModerationObjectType = z.enum(['review', 'reply'])
export type ModerationObjectType = z.infer<typeof ModerationObjectType>

export const ModerationAction = z.enum(['hide', 'restore'])
export type ModerationAction = z.infer<typeof ModerationAction>

export const ModerationLogDTO = z.object({
  id: z.number().int().positive(),
  object_type: ModerationObjectType,
  object_id: z.number().int().positive(),
  action: ModerationAction,
  reason: z.string(),
  staff_id: z.number().int().positive(),
  staff_login: z.string(),
  restorable: z.boolean(),
  created_at: z.string(),
})
export type ModerationLogDTO = z.infer<typeof ModerationLogDTO>

export const ModerationLogListDTO = z.object({
  items: z.array(ModerationLogDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(100),
})
export type ModerationLogListDTO = z.infer<typeof ModerationLogListDTO>
