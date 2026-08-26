import { describe, expect, it } from 'vitest';
import {
  CreatePostInput,
  CreateStaffInput,
  PostDTO,
  UpdateStaffInput,
} from '../org.js';

describe('Phase 8 organization contracts', () => {
  it('requires post role bindings in responses', () => {
    const post = {
      id: 4,
      department_id: 2,
      department_name: '内容部',
      name: '课程运营',
      status: 'enabled',
      created_at: '2026-08-25 12:00:00',
      updated_at: '2026-08-25 12:00:00',
    };

    expect(PostDTO.safeParse(post).success).toBe(false);
    expect(PostDTO.parse({ ...post, role_ids: [3, 5] }).role_ids).toEqual([3, 5]);
  });

  it('preserves role ids when creating a post', () => {
    const parsed = CreatePostInput.parse({
      department_id: 2,
      name: '课程运营',
      status: 'enabled',
      role_ids: [3, 5],
    });

    expect(parsed.role_ids).toEqual([3, 5]);
  });

  it('requires an ordinary staff member to have a department', () => {
    const base = {
      login: 'course.operator',
      password: 'safe-pass-123',
      display_name: '课程运营',
      role_ids: [],
      post_ids: [],
    };

    expect(CreateStaffInput.safeParse({ ...base, is_super_admin: false }).success).toBe(false);
    expect(CreateStaffInput.safeParse({ ...base, is_super_admin: true }).success).toBe(true);
    expect(
      CreateStaffInput.safeParse({ ...base, is_super_admin: false, department_id: 2 }).success,
    ).toBe(true);
  });

  it('requires a new password when password reset is requested', () => {
    expect(UpdateStaffInput.safeParse({ reset_password: true }).success).toBe(false);
    expect(
      UpdateStaffInput.safeParse({ reset_password: true, new_password: 'new-pass-123' }).success,
    ).toBe(true);
  });
});
