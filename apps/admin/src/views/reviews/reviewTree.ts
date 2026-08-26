import type { ReviewReplyDTO } from '@learn-site/contracts';

export interface ReviewReplyTreeNode {
  reply: ReviewReplyDTO;
  children: ReviewReplyTreeNode[];
}

export function buildReviewReplyTree(replies: ReviewReplyDTO[]): ReviewReplyTreeNode[] {
  const nodes = new Map<number, ReviewReplyTreeNode>();
  for (const reply of replies) {
    nodes.set(reply.id, { reply, children: [] });
  }

  const roots: ReviewReplyTreeNode[] = [];
  for (const reply of replies) {
    const node = nodes.get(reply.id);
    if (!node) continue;
    const parent = reply.parent_id === null ? undefined : nodes.get(reply.parent_id);
    if (parent && parent !== node) {
      parent.children.push(node);
    } else {
      roots.push(node);
    }
  }
  return roots;
}
