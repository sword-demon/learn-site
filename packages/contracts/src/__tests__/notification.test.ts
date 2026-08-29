import { describe, expect, it } from 'vitest';
import {
  LearnerNotificationDTO,
  LearnerUnreadCountDTO,
} from '../notification.js';

describe('notification contracts', () => {
  it('accepts extended learner notification kinds', () => {
    const parsed = LearnerNotificationDTO.parse({
      id: 1,
      kind: 'announcement',
      title: '公告',
      body: '正文',
      dispatch_id: 9,
      resource_type: null,
      resource_id: null,
      resource_available: false,
      payload: null,
      read: false,
      created_at: '2026-08-29T10:00:00+08:00',
    });
    expect(parsed.kind).toBe('announcement');
  });

  it('parses unread count', () => {
    expect(LearnerUnreadCountDTO.parse({ count: 3 }).count).toBe(3);
  });
});
