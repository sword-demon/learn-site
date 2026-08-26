import { z } from 'zod'

export const AuditLogDTO = z.object({
  id: z.number().int(),
  actor_id: z.number().int().nullable(),
  actor_login: z.string().nullable(),
  action: z.string(),
  target_type: z.string(),
  target_id: z.number().int().nullable(),
  payload_json: z.string(),
  created_at: z.string(),
})
export type AuditLogDTO = z.infer<typeof AuditLogDTO>

export const AuditLogListDTO = z.object({
  items: z.array(AuditLogDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
})
export type AuditLogListDTO = z.infer<typeof AuditLogListDTO>
