import { z } from "zod";

export const DashboardScope = z.enum(["all", "restricted"]);
export type DashboardScope = z.infer<typeof DashboardScope>;

const ScopedCount = z.number().int().nonnegative().nullable();

export const DashboardCountsDTO = z.object({
  unanswered_questions: ScopedCount,
  pending_reviews: ScopedCount,
  abnormal_learning_maps: ScopedCount,
  unpublished_courses: ScopedCount,
  pending_orders: ScopedCount,
  succeeded_orders: ScopedCount,
  paid_amount: z.number().nonnegative().nullable(),
  published_courses: ScopedCount,
});
export type DashboardCountsDTO = z.infer<typeof DashboardCountsDTO>;

const DashboardOperationsDTO = z.object({
  unanswered_questions: ScopedCount,
  pending_reviews: ScopedCount,
  abnormal_learning_maps: ScopedCount,
  unpublished_courses: ScopedCount,
});

const DashboardCourseInventoryDTO = z.object({
  draft: ScopedCount,
  published: ScopedCount,
  unpublished: ScopedCount,
});

export const DashboardOrderTrendPointDTO = z.object({
  date: z.string(),
  created_orders: z.number().int().nonnegative(),
  succeeded_orders: z.number().int().nonnegative(),
  paid_amount: z.number().nonnegative(),
});
export type DashboardOrderTrendPointDTO = z.infer<
  typeof DashboardOrderTrendPointDTO
>;

export const DashboardOperationsContentDTO = z.object({
  operations: DashboardOperationsDTO,
  course_inventory: DashboardCourseInventoryDTO,
});
export type DashboardOperationsContentDTO = z.infer<
  typeof DashboardOperationsContentDTO
>;

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
  timezone: z.string(),
  range_days: z.union([z.literal(7), z.literal(30), z.literal(90)]),
  counts: DashboardCountsDTO,
  order_trend: z.array(DashboardOrderTrendPointDTO).nullable(),
  operations_content: DashboardOperationsContentDTO,
  recent_orders: z.array(DashboardRecentOrderDTO).nullable(),
});
export type DashboardSummaryDTO = z.infer<typeof DashboardSummaryDTO>;
