import { z } from 'zod'

export const LearnerNotificationKind = z.enum([
  'question_update',
  'progress_reset',
  'entitlement_revoked',
  'announcement',
  'internal_message',
])

export const LearnerNotificationDTO = z.object({
  id: z.number().int(),
  kind: LearnerNotificationKind,
  title: z.string(),
  body: z.string().nullable(),
  dispatch_id: z.number().int().positive().nullable().optional(),
  resource_type: z.enum(['question', 'course']).nullable(),
  resource_id: z.number().int().positive().nullable(),
  resource_available: z.boolean(),
  payload: z.unknown().nullable(),
  read: z.boolean(),
  created_at: z.string(),
})
export type LearnerNotificationDTO = z.infer<typeof LearnerNotificationDTO>

export const LearnerNotificationListDTO = z.object({
  items: z.array(LearnerNotificationDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type LearnerNotificationListDTO = z.infer<typeof LearnerNotificationListDTO>

export const LearnerNotificationReadDTO = z.object({
  read: z.literal(true),
})
export type LearnerNotificationReadDTO = z.infer<typeof LearnerNotificationReadDTO>

export const LearnerUnreadCountDTO = z.object({
  count: z.number().int().nonnegative(),
})
export type LearnerUnreadCountDTO = z.infer<typeof LearnerUnreadCountDTO>
