import { z } from 'zod'

export const NotificationType = z.enum(['announcement', 'internal_message', 'course_published'])
export type NotificationType = z.infer<typeof NotificationType>

export const AdminNotificationListItemDTO = z.object({
  id: z.number().int(),
  type: NotificationType,
  title: z.string(),
  resource_type: z.string().nullable().optional(),
  resource_id: z.number().int().positive().nullable().optional(),
  fan_out_status: z
    .enum(['pending', 'running', 'completed', 'failed'])
    .nullable()
    .optional(),
  fan_out_done_count: z.number().int().nonnegative().nullable().optional(),
  fan_out_error: z.string().nullable().optional(),
  sender_staff_id: z.number().int(),
  sender_login: z.string(),
  recipient_summary: z.string(),
  recipient_count: z.number().int().nonnegative(),
  created_at: z.string(),
})
export type AdminNotificationListItemDTO = z.infer<typeof AdminNotificationListItemDTO>

export const AdminNotificationRecipientDTO = z.object({
  id: z.number().int(),
  login: z.string(),
  display_name: z.string().nullable(),
})
export type AdminNotificationRecipientDTO = z.infer<typeof AdminNotificationRecipientDTO>

export const AdminNotificationDetailDTO = AdminNotificationListItemDTO.extend({
  body: z.string(),
  recipients: z.array(AdminNotificationRecipientDTO).optional(),
})
export type AdminNotificationDetailDTO = z.infer<typeof AdminNotificationDetailDTO>

export const AdminNotificationListDTO = z.object({
  items: z.array(AdminNotificationListItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type AdminNotificationListDTO = z.infer<typeof AdminNotificationListDTO>

export const SendAnnouncementInput = z.object({
  title: z.string().min(1).max(200),
  body: z.string().min(1).max(10000),
})
export type SendAnnouncementInput = z.infer<typeof SendAnnouncementInput>

export const SendInternalMessageInput = z.object({
  title: z.string().min(1).max(200),
  body: z.string().min(1).max(10000),
  learner_ids: z.array(z.number().int().positive()).min(1),
})
export type SendInternalMessageInput = z.infer<typeof SendInternalMessageInput>
