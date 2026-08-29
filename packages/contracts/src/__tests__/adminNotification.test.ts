import { describe, expect, it } from 'vitest';
import {
  AdminNotificationDetailDTO,
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
});
