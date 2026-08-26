import { ApiResponse, AuditLogListDTO } from '@learn-site/contracts'
import { http } from '@/api/http'

export { AuditLogListDTO }

export interface AuditLogListParams {
  action?: string
  target_type?: string
  actor_id?: number
  page?: number
  limit?: number
}

export async function listAuditLogs(params: AuditLogListParams = {}): Promise<AuditLogListDTO> {
  const { data } = await http.get('/audit', { params })
  const parsed = ApiResponse(AuditLogListDTO).parse(data)
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code })
  }
  return parsed.data
}
