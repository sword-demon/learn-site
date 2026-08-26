import { z } from 'zod'

export const LearnerNotificationDTO = z.object({
  id: z.number().int(),
  kind: z.string(),
  title: z.string(),
  body: z.string().nullable(),
  payload: z.unknown().nullable(),
  read: z.boolean(),
  created_at: z.string(),
})
export type LearnerNotificationDTO = z.infer<typeof LearnerNotificationDTO>

export const LearnerNotificationListDTO = z.object({
  items: z.array(LearnerNotificationDTO),
})
export type LearnerNotificationListDTO = z.infer<typeof LearnerNotificationListDTO>

export const LearnerNotificationReadDTO = z.object({
  read: z.literal(true),
})
export type LearnerNotificationReadDTO = z.infer<typeof LearnerNotificationReadDTO>
