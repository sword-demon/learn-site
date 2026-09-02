import {
  AdminNotificationDetailDTO,
  AdminNotificationListDTO,
  AdminNotificationListItemDTO,
  ApiResponse,
  SendAnnouncementInput,
  SendInternalMessageInput,
  type AdminNotificationDetailDTO as AdminNotificationDetail,
  type AdminNotificationListItemDTO as AdminNotificationListItem,
  type AdminNotificationListDTO as AdminNotificationList,
} from '@learn-site/contracts';
import { http } from '@/api/http';

export type { AdminNotificationDetail, AdminNotificationList, AdminNotificationListItem };

export interface NotificationListParams {
  type?: 'announcement' | 'internal_message' | 'course_published' | '';
  from?: string;
  to?: string;
  page?: number;
  limit?: number;
}

export async function listNotifications(
  params: NotificationListParams = {},
): Promise<AdminNotificationList> {
  const { data } = await http.get('/notifications', { params });
  const parsed = ApiResponse(AdminNotificationListDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function getNotification(id: number): Promise<AdminNotificationDetail> {
  const { data } = await http.get(`/notifications/${id}`);
  const parsed = ApiResponse(AdminNotificationDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function sendAnnouncement(
  payload: SendAnnouncementInput,
): Promise<AdminNotificationDetail> {
  const body = SendAnnouncementInput.parse(payload);
  const { data } = await http.post('/notifications/announcements', body);
  const parsed = ApiResponse(AdminNotificationDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function sendInternalMessage(
  payload: SendInternalMessageInput,
): Promise<AdminNotificationDetail> {
  const body = SendInternalMessageInput.parse(payload);
  const { data } = await http.post('/notifications/internal-messages', body);
  const parsed = ApiResponse(AdminNotificationDetailDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}

export async function retryNotificationFanOut(id: number): Promise<AdminNotificationListItem> {
  const { data } = await http.post(`/notifications/${id}/retry`);
  const parsed = ApiResponse(AdminNotificationListItemDTO).parse(data);
  if (!parsed.ok) {
    throw Object.assign(new Error(parsed.error.code), { code: parsed.error.code });
  }
  return parsed.data;
}
