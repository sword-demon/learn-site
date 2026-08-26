import { beforeEach, describe, expect, it, vi } from 'vitest';
import * as reviewsApi from '@/api/reviews';

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp }));

describe('admin review API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('loads course filters from the review permission boundary', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: { courses: [{ id: 12, title: 'TypeScript 深入实践' }] },
    });
    const fetchOptions = Reflect.get(reviewsApi, 'fetchModerationFilterOptions') as
      (() => Promise<unknown>) | undefined;

    expect(fetchOptions).toBeTypeOf('function');
    await fetchOptions?.();

    expect(mockHttp.get).toHaveBeenCalledWith('/reviews/filter-options');
  });

  it('uses the reply moderation endpoints with a required reason', async () => {
    const thread = {
      review: {
        id: 56,
        course_id: 12,
        learner_id: 78,
        viewer_owned: false,
        author_name: '林同学',
        rating: 5,
        body: '课程结构清晰。',
        visibility: 'public',
        hidden_reason: null,
        hidden_by_staff_id: null,
        hidden_at: null,
        edited: false,
        created_at: '2026-08-25 10:30:00',
        updated_at: '2026-08-25 10:30:00',
      },
      replies: [],
    };
    mockHttp.post.mockResolvedValue({ data: thread });
    const hideReply = Reflect.get(reviewsApi, 'hideReviewReply') as
      ((id: number, input: { reason: string }) => Promise<unknown>) | undefined;
    const restoreReply = Reflect.get(reviewsApi, 'restoreReviewReply') as
      ((id: number) => Promise<unknown>) | undefined;

    expect(hideReply).toBeTypeOf('function');
    expect(restoreReply).toBeTypeOf('function');
    await hideReply?.(90, { reason: '包含广告链接' });
    await restoreReply?.(90);

    expect(mockHttp.post).toHaveBeenNthCalledWith(1, '/review-replies/90/hide', {
      reason: '包含广告链接',
    });
    expect(mockHttp.post).toHaveBeenNthCalledWith(2, '/review-replies/90/restore');
  });
});
