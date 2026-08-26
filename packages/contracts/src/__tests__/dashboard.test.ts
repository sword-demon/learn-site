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
