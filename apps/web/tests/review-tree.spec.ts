import { describe, expect, it } from 'vitest';
import type { ReviewDTO, ReviewReplyDTO } from '@learn-site/contracts';
import { buildReplyForest, findViewerReview } from '@/views/catalog/reviewTreeModel';

function review(overrides: Partial<ReviewDTO> = {}): ReviewDTO {
  return {
    id: 1,
    course_id: 2,
    learner_id: null,
    viewer_owned: false,
    author_name: '匿名学员',
    rating: 5,
    body: '评价正文',
    visibility: 'public',
    hidden_reason: null,
    hidden_by_staff_id: null,
    hidden_at: null,
    edited: false,
    created_at: '2026-08-25 10:00:00',
    updated_at: '2026-08-25 10:00:00',
    ...overrides,
  };
}

function reply(
  id: number,
  parentId: number | null,
  overrides: Partial<ReviewReplyDTO> = {},
): ReviewReplyDTO {
  return {
    id,
    review_id: 1,
    parent_id: parentId,
    kind: 'learner',
    author_learner_id: null,
    author_staff_id: null,
    viewer_owned: false,
    author_name: '匿名学员',
    body: `回复 ${id}`,
    visibility: 'public',
    hidden_reason: null,
    hidden_by_staff_id: null,
    hidden_at: null,
    edited: false,
    created_at: '2026-08-25 10:00:00',
    updated_at: '2026-08-25 10:00:00',
    ...overrides,
  };
}

describe('review tree model', () => {
  it('builds a stable three-level reply forest from a flat response', () => {
    const forest = buildReplyForest([reply(3, 2), reply(1, null), reply(2, 1), reply(4, null)]);

    expect(forest.map((node) => node.id)).toEqual([1, 4]);
    expect(forest[0]?.children[0]?.id).toBe(2);
    expect(forest[0]?.children[0]?.children[0]?.id).toBe(3);
  });

  it('drops orphaned replies instead of rendering them at the wrong level', () => {
    const forest = buildReplyForest([reply(1, null), reply(2, 999)]);

    expect(forest).toHaveLength(1);
    expect(forest[0]?.id).toBe(1);
  });

  it('finds the viewer-owned review without relying on private account ids', () => {
    const own = review({ id: 2, viewer_owned: true });

    expect(findViewerReview([review(), own])).toEqual(own);
    expect(findViewerReview([review()])).toBeNull();
  });
});
