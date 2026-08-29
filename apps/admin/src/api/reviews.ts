import http from './http';
import {
  ApiOk,
  HideReviewInput,
  PostReplyInput,
  ReviewFilterOptionsDTO,
  ReviewListDTO,
  ReviewReplyDTO,
  ReviewThreadDTO,
  type ReviewVisibility,
} from '@learn-site/contracts';

/**
 * Admin reviews moderation API (Phase 12 / US5 — T072).
 * Endpoints are defined in apps/api/app/controller/admin/ReviewController.php
 * and matched by the Authorize middleware to `review.view` / `review.moderate`.
 */

export interface ModerationListParams {
  course_id: number;
  visibility?: ReviewVisibility | 'all';
  page?: number;
  limit?: number;
}

const ReviewListEnvelope = ApiOk(ReviewListDTO);
const ReviewFilterOptionsEnvelope = ApiOk(ReviewFilterOptionsDTO);
const ReviewThreadEnvelope = ApiOk(ReviewThreadDTO);
const ReviewReplyEnvelope = ApiOk(ReviewReplyDTO);

export async function listForModeration(params: ModerationListParams): Promise<ReviewListDTO> {
  const { data } = await http.get<unknown>('/reviews', { params });
  return ReviewListEnvelope.parse(data).data;
}

export async function fetchModerationFilterOptions(): Promise<ReviewFilterOptionsDTO> {
  const { data } = await http.get<unknown>('/reviews/filter-options');
  return ReviewFilterOptionsEnvelope.parse(data).data;
}

export async function fetchAdminThread(id: number): Promise<ReviewThreadDTO> {
  const { data } = await http.get<unknown>(`/reviews/${id}`);
  return ReviewThreadEnvelope.parse(data).data;
}

export async function hideReview(id: number, input: HideReviewInput): Promise<ReviewThreadDTO> {
  const { data } = await http.post<unknown>(`/reviews/${id}/hide`, HideReviewInput.parse(input));
  return ReviewThreadEnvelope.parse(data).data;
}

export async function restoreReview(id: number): Promise<ReviewThreadDTO> {
  const { data } = await http.post<unknown>(`/reviews/${id}/restore`);
  return ReviewThreadEnvelope.parse(data).data;
}

export async function postReviewReply(id: number, input: PostReplyInput): Promise<ReviewReplyDTO> {
  const { data } = await http.post<unknown>(`/reviews/${id}/replies`, PostReplyInput.parse(input));
  return ReviewReplyEnvelope.parse(data).data;
}

export async function hideReviewReply(
  id: number,
  input: HideReviewInput,
): Promise<ReviewThreadDTO> {
  const { data } = await http.post<unknown>(
    `/review-replies/${id}/hide`,
    HideReviewInput.parse(input),
  );
  return ReviewThreadEnvelope.parse(data).data;
}

export async function restoreReviewReply(id: number): Promise<ReviewThreadDTO> {
  const { data } = await http.post<unknown>(`/review-replies/${id}/restore`);
  return ReviewThreadEnvelope.parse(data).data;
}
