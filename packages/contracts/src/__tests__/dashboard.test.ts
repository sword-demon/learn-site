import { describe, expect, it } from "vitest";
import { DashboardSummaryDTO } from "../dashboard.js";

describe("DashboardSummaryDTO", () => {
  it("accepts restricted summaries and nulls for unavailable modules", () => {
    const result = DashboardSummaryDTO.safeParse({
      scope: "restricted",
      counts: {
        unanswered_questions: 2,
        pending_reviews: null,
        abnormal_learning_maps: 1,
        unpublished_courses: 3,
        pending_orders: null,
        succeeded_orders: null,
        paid_amount: null,
        published_courses: 4,
      },
      timezone: "Asia/Shanghai",
      range_days: 30,
      order_trend: null,
      operations_content: {
        operations: {
          unanswered_questions: 2,
          pending_reviews: null,
          abnormal_learning_maps: 1,
          unpublished_courses: 3,
        },
        course_inventory: { draft: 3, published: 4, unpublished: 1 },
      },
      recent_orders: null,
    });

    expect(result.success).toBe(true);
  });

  it("accepts the five most recent in-scope orders when order access is granted", () => {
    const result = DashboardSummaryDTO.safeParse({
      scope: "all",
      counts: {
        unanswered_questions: 0,
        pending_reviews: 0,
        abnormal_learning_maps: 0,
        unpublished_courses: 0,
        pending_orders: 2,
        succeeded_orders: 1,
        paid_amount: 199,
        published_courses: 5,
      },
      timezone: "Asia/Shanghai",
      range_days: 7,
      order_trend: [
        {
          date: "2026-08-25",
          created_orders: 1,
          succeeded_orders: 1,
          paid_amount: 199,
        },
      ],
      operations_content: {
        operations: {
          unanswered_questions: 0,
          pending_reviews: 0,
          abnormal_learning_maps: 0,
          unpublished_courses: 0,
        },
        course_inventory: { draft: 2, published: 5, unpublished: 1 },
      },
      recent_orders: [
        {
          id: 9,
          course_id: 4,
          course_title: "可靠的后台工作台",
          status: "succeeded",
          paid_amount: 199,
          created_at: "2026-08-25 10:00:00",
        },
      ],
    });

    expect(result.success).toBe(true);
  });
});
