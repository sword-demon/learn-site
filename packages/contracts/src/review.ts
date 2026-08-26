import { z } from "zod";

// ─── reviews + replies (Phase 12 / US5) ─────────────────────────────

export const ReviewVisibility = z.enum(["public", "hidden"]);
export type ReviewVisibility = z.infer<typeof ReviewVisibility>;

export const ReviewReplyKind = z.enum(["learner", "admin", "system"]);
export type ReviewReplyKind = z.infer<typeof ReviewReplyKind>;

export const ReviewDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  learner_id: z.number().int().positive().nullable(),
  viewer_owned: z.boolean(),
  author_name: z.string().min(1),
  rating: z.number().int().min(1).max(5),
  body: z.string(),
  visibility: ReviewVisibility,
  hidden_reason: z.string().nullable(),
  hidden_by_staff_id: z.number().int().positive().nullable(),
  hidden_at: z.string().nullable(),
  edited: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type ReviewDTO = z.infer<typeof ReviewDTO>;

export const ReviewReplyDTO = z.object({
  id: z.number().int().positive(),
  review_id: z.number().int().positive(),
  parent_id: z.number().int().positive().nullable(),
  kind: ReviewReplyKind,
  author_learner_id: z.number().int().positive().nullable(),
  author_staff_id: z.number().int().positive().nullable(),
  viewer_owned: z.boolean(),
  author_name: z.string().min(1),
  body: z.string(),
  visibility: ReviewVisibility,
  hidden_reason: z.string().nullable(),
  hidden_by_staff_id: z.number().int().positive().nullable(),
  hidden_at: z.string().nullable(),
  edited: z.boolean(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type ReviewReplyDTO = z.infer<typeof ReviewReplyDTO>;

export const ReviewListDTO = z.object({
  items: z.array(ReviewDTO),
  viewer_review: ReviewDTO.nullable(),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type ReviewListDTO = z.infer<typeof ReviewListDTO>;

export const ReviewThreadDTO = z.object({
  review: ReviewDTO,
  replies: z.array(ReviewReplyDTO),
});
export type ReviewThreadDTO = z.infer<typeof ReviewThreadDTO>;

export const ReviewFilterOptionDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
});
export type ReviewFilterOptionDTO = z.infer<typeof ReviewFilterOptionDTO>;

export const ReviewFilterOptionsDTO = z.object({
  courses: z.array(ReviewFilterOptionDTO),
});
export type ReviewFilterOptionsDTO = z.infer<typeof ReviewFilterOptionsDTO>;

export const PostReviewInput = z.object({
  rating: z.number().int().min(1).max(5),
  body: z.string().min(1).max(4000),
});
export type PostReviewInput = z.infer<typeof PostReviewInput>;

export const UpdateReviewInput = PostReviewInput;
export type UpdateReviewInput = z.infer<typeof UpdateReviewInput>;

export const PostReplyInput = z.object({
  body: z.string().min(1).max(4000),
  parent_id: z.number().int().positive().nullable().optional(),
});
export type PostReplyInput = z.infer<typeof PostReplyInput>;

export const HideReviewInput = z.object({
  reason: z.string().min(1).max(255),
});
export type HideReviewInput = z.infer<typeof HideReviewInput>;

export const HideReviewReplyInput = HideReviewInput;
export type HideReviewReplyInput = z.infer<typeof HideReviewReplyInput>;

export const DeleteReviewDTO = z.object({ deleted: z.literal(true) });
export type DeleteReviewDTO = z.infer<typeof DeleteReviewDTO>;
