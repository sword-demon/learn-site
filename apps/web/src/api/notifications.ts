import { ApiResponse, LearnerNotificationListDTO, LearnerNotificationReadDTO } from '@learn-site/contracts'
import { http } from '@/api/http'

export { LearnerNotificationListDTO, LearnerNotificationReadDTO }

export async function listNotifications(): Promise<LearnerNotificationListDTO> {
  const { data } = await http.get('/me/notifications')
  const parsed = ApiResponse(LearnerNotificationListDTO).parse(data)
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code })
  }
  return parsed.data
}

export async function markNotificationRead(id: number): Promise<LearnerNotificationReadDTO> {
  const { data } = await http.post(`/me/notifications/${id}/read`)
  const parsed = ApiResponse(LearnerNotificationReadDTO).parse(data)
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code })
  }
  return parsed.data
}
