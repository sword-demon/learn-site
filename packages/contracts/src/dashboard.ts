import { z } from "zod";

export const DashboardScope = z.enum(["all", "restricted"]);
export type DashboardScope = z.infer<typeof DashboardScope>;

const ScopedCount = z.number().int().nonnegative().nullable();

export const DashboardCountsDTO = z.object({
  unanswered_questions: ScopedCount,
  pending_reviews: ScopedCount,
  abnormal_learning_maps: ScopedCount,
  unpublished_courses: ScopedCount,
});
export type DashboardCountsDTO = z.infer<typeof DashboardCountsDTO>;

export const DashboardRecentOrderDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  course_title: z.string(),
  status: z.enum(["pending", "succeeded", "failed", "cancelled", "unknown"]),
  paid_amount: z.number().nonnegative(),
  created_at: z.string(),
});
export type DashboardRecentOrderDTO = z.infer<typeof DashboardRecentOrderDTO>;

export const DashboardSummaryDTO = z.object({
  scope: DashboardScope,
  counts: DashboardCountsDTO,
  recent_orders: z.array(DashboardRecentOrderDTO).nullable(),
});
export type DashboardSummaryDTO = z.infer<typeof DashboardSummaryDTO>;
