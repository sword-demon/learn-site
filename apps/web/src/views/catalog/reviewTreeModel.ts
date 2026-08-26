import type { ReviewDTO, ReviewReplyDTO } from '@learn-site/contracts';

export interface ReviewReplyNode extends ReviewReplyDTO {
  children: ReviewReplyNode[];
}

export function buildReplyForest(replies: ReviewReplyDTO[]): ReviewReplyNode[] {
  const nodes = new Map<number, ReviewReplyNode>();
  for (const reply of replies) {
    nodes.set(reply.id, { ...reply, children: [] });
  }

  const roots: ReviewReplyNode[] = [];
  for (const reply of replies) {
    const node = nodes.get(reply.id);
    if (!node) continue;
    if (reply.parent_id === null) {
      roots.push(node);
      continue;
    }
    const parent = nodes.get(reply.parent_id);
    if (parent && parent.id !== node.id) {
      parent.children.push(node);
    }
  }
  return roots;
}

export function findViewerReview(reviews: ReviewDTO[]): ReviewDTO | null {
  return reviews.find((review) => review.viewer_owned) ?? null;
}
