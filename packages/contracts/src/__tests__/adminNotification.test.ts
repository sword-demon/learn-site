import { describe, expect, it } from 'vitest';
import {
  AdminNotificationDetailDTO,
  AdminNotificationListItemDTO,
  AdminNotificationListDTO,
  SendAnnouncementInput,
  SendInternalMessageInput,
} from '../adminNotification.js';

describe('adminNotification contracts', () => {
  it('parses announcement send input', () => {
    expect(
      SendAnnouncementInput.parse({ title: '标题', body: '正文' }).title,
    ).toBe('标题');
  });

  it('requires at least one learner for internal message', () => {
    expect(
      SendInternalMessageInput.safeParse({
        title: '标题',
        body: '正文',
        learner_ids: [],
      }).success,
    ).toBe(false);
    expect(
      SendInternalMessageInput.safeParse({
        title: '标题',
        body: '正文',
        learner_ids: [1],
      }).success,
    ).toBe(true);
  });

  it('parses admin list and detail envelopes', () => {
    const list = AdminNotificationListDTO.parse({
      items: [
        {
          id: 1,
          type: 'announcement',
          title: '公告',
          sender_staff_id: 2,
          sender_login: 'admin',
          recipient_summary: '全体学员',
          recipient_count: 10,
          created_at: '2026-08-29T10:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    });
    expect(list.total).toBe(1);

    const detail = AdminNotificationDetailDTO.parse({
      ...list.items[0],
      body: '正文',
    });
    expect(detail.body).toBe('正文');
  });

  it('accepts course_published dispatch rows with resource and fan-out fields', () => {
    const item = AdminNotificationListItemDTO.parse({
      id: 7,
      type: 'course_published',
      title: '新课上线:示例课',
      resource_type: 'course',
      resource_id: 42,
      fan_out_status: 'failed',
      fan_out_done_count: 0,
      fan_out_error: 'queue_unavailable',
      sender_staff_id: 2,
      sender_login: 'admin',
      recipient_summary: '全体在册学员',
      recipient_count: 10000,
      created_at: '2026-09-02T10:00:00+08:00',
    });
    expect(item.type).toBe('course_published');
    expect(item.resource_id).toBe(42);
    expect(item.fan_out_status).toBe('failed');

    // Legacy rows keep the nullable contract shape.
    const legacy = AdminNotificationListItemDTO.parse({
      id: 8,
      type: 'announcement',
      title: '公告',
      sender_staff_id: 2,
      sender_login: 'admin',
      recipient_summary: '全体学员',
      recipient_count: 1,
      created_at: '2026-09-02T10:00:00+08:00',
    });
    expect(legacy.resource_type).toBeUndefined();
    expect(legacy.fan_out_status).toBeUndefined();
  });

  it('rejects unknown notification types', () => {
    expect(
      AdminNotificationListItemDTO.safeParse({
        id: 9,
        type: 'course_released',
        title: '未知',
        sender_staff_id: 2,
        sender_login: 'admin',
        recipient_summary: '全体学员',
        recipient_count: 1,
        created_at: '2026-09-02T10:00:00+08:00',
      }).success,
    ).toBe(false);
  });
});
