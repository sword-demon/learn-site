import { describe, expect, it } from 'vitest';
import {
  LearnerNotificationDTO,
  LearnerNotificationKind,
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

  it('accepts the course_published kind pointing at a course', () => {
    expect(LearnerNotificationKind.safeParse('course_published').success).toBe(true);
    const parsed = LearnerNotificationDTO.parse({
      id: 2,
      kind: 'course_published',
      title: '示例课',
      body: '新课程已发布',
      dispatch_id: 10,
      resource_type: 'course',
      resource_id: 42,
      resource_available: true,
      payload: null,
      read: false,
      created_at: '2026-09-02T10:00:00+08:00',
    });
    expect(parsed.kind).toBe('course_published');
    expect(parsed.resource_id).toBe(42);
  });

  it('parses unread count', () => {
    expect(LearnerUnreadCountDTO.parse({ count: 3 }).count).toBe(3);
  });
});
