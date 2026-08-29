import { describe, expect, it } from 'vitest';

import { CourseStudentDTO } from '../courseStudent.js';

describe('course student contract', () => {
  it('uses learner profile and progress fields without staff department fields', () => {
    const parsed = CourseStudentDTO.parse({
      account_id: 7,
      login: '13912345678',
      nickname: '小王',
      account_status: 'active',
      source: 'free',
      entitlement_status: 'active',
      progress_percent: 40,
      learning_status: 'in_progress',
      last_learning_at: '2026-08-28 11:00:00',
      completed_at: null,
      enrolled_at: '2026-08-28 10:00:00',
      revoked_at: null,
      revoked_reason: null,
      last_login_at: null,
    });

    expect(parsed.nickname).toBe('小王');
    expect(parsed.progress_percent).toBe(40);
    expect(parsed.learning_status).toBe('in_progress');
    expect(parsed.last_learning_at).toBe('2026-08-28 11:00:00');
    expect(parsed).not.toHaveProperty('department_id');
  });
});
