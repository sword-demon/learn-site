import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp }));

import {
  answerQuestion,
  closeQuestion,
  fetchFilterOptions,
  fetchInbox,
  fetchThread,
} from '@/api/qa';

const question = {
  id: 56,
  course_id: 12,
  chapter_id: null,
  lesson_id: null,
  learner_id: 78,
  title: '如何理解条件类型？',
  status: 'pending' as const,
  answered_at: '',
  created_at: '2026-08-25 10:30:00',
  updated_at: '2026-08-25 10:30:00',
};

const thread = { question, messages: [] };

describe('admin Q&A API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('uses the questions contract path and forwards all inbox filters', async () => {
    const inbox = { items: [], total: 0, page: 2, limit: 20, status: 'answered' };
    mockHttp.get.mockResolvedValueOnce({
      data: { ok: true, data: inbox },
    });

    await expect(
      fetchInbox({
        status: 'answered',
        course_id: 12,
        lesson_id: 34,
        page: 2,
        limit: 20,
      }),
    ).resolves.toEqual(inbox);

    expect(mockHttp.get).toHaveBeenCalledWith('/questions', {
      params: {
        status: 'answered',
        course_id: 12,
        lesson_id: 34,
        page: 2,
        limit: 20,
      },
    });
  });

  it('loads scoped filter options from the Q&A permission boundary', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          courses: [{ id: 12, title: 'TypeScript 深入实践' }],
          lessons: [{ id: 34, title: '条件类型' }],
        },
      },
    });
    await expect(fetchFilterOptions(12)).resolves.toEqual({
      courses: [{ id: 12, title: 'TypeScript 深入实践' }],
      lessons: [{ id: 34, title: '条件类型' }],
    });
    expect(mockHttp.get).toHaveBeenCalledWith('/questions/filter-options', {
      params: { course_id: 12 },
    });
  });

  it('uses the questions contract paths for thread actions', async () => {
    mockHttp.get.mockResolvedValueOnce({ data: { ok: true, data: thread } });
    mockHttp.post.mockResolvedValue({ data: { ok: true, data: thread } });

    await fetchThread(56);
    await answerQuestion(56, { body: '补充说明' });
    await closeQuestion(56);

    expect(mockHttp.get).toHaveBeenCalledWith('/questions/56');
    expect(mockHttp.post).toHaveBeenNthCalledWith(1, '/questions/56/answer', { body: '补充说明' });
    expect(mockHttp.post).toHaveBeenNthCalledWith(2, '/questions/56/close');
  });
});
