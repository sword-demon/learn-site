import { beforeEach, describe, expect, it, vi } from 'vitest';

const httpApi = vi.hoisted(() => ({
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http: httpApi }));

import { postFollowup } from '@/api/learner';

describe('learner question API', () => {
  beforeEach(() => vi.clearAllMocks());

  it('posts learner follow-ups to the canonical question messages path', async () => {
    const thread = {
      question: {
        id: 56,
        course_id: 12,
        chapter_id: null,
        lesson_id: 34,
        learner_id: 78,
        title: '如何理解条件类型？',
        status: 'pending',
        answered_at: '',
        created_at: '2026-08-25 10:30:00',
        updated_at: '2026-08-25 10:30:00',
      },
      messages: [],
    };
    httpApi.post.mockResolvedValueOnce({ data: { ok: true, data: thread } });

    await expect(postFollowup(56, { body: '请再举一个例子' })).resolves.toEqual(thread);
    expect(httpApi.post).toHaveBeenCalledWith('/questions/56/messages', {
      body: '请再举一个例子',
    });
  });
});
