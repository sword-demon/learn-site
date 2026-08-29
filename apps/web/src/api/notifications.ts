import {
  ApiResponse,
  LearnerNotificationListDTO,
  LearnerNotificationReadDTO,
  LearnerUnreadCountDTO,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export { LearnerNotificationListDTO, LearnerNotificationReadDTO, LearnerUnreadCountDTO };

export async function listNotifications(page = 1, limit = 20): Promise<LearnerNotificationListDTO> {
  const { data } = await http.get('/messages', { params: { page, limit } });
  const parsed = ApiResponse(LearnerNotificationListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function fetchUnreadCount(): Promise<LearnerUnreadCountDTO> {
  const { data } = await http.get('/messages/unread-count');
  const parsed = ApiResponse(LearnerUnreadCountDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function markNotificationRead(id: number): Promise<LearnerNotificationReadDTO> {
  const { data } = await http.post(`/messages/${id}/read`);
  const parsed = ApiResponse(LearnerNotificationReadDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
