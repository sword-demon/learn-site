import { describe, expect, it } from "vitest";

import {
  CourseStartQueueItemDTO,
  CourseStartQueueListDTO,
  CourseStudentDTO,
} from "../courseStudent.js";

describe("course student contract", () => {
  it("uses learner profile and progress fields without staff department fields", () => {
    const parsed = CourseStudentDTO.parse({
      account_id: 7,
      login: "13912345678",
      nickname: "小王",
      account_status: "active",
      source: "free",
      entitlement_status: "active",
      progress_percent: 40,
      learning_status: "in_progress",
      last_learning_at: "2026-08-28 11:00:00",
      completed_at: null,
      enrolled_at: "2026-08-28 10:00:00",
      revoked_at: null,
      revoked_reason: null,
      last_login_at: null,
    });

    expect(parsed.nickname).toBe("小王");
    expect(parsed.progress_percent).toBe(40);
    expect(parsed.learning_status).toBe("in_progress");
    expect(parsed.last_learning_at).toBe("2026-08-28 11:00:00");
    expect(parsed).not.toHaveProperty("department_id");
  });

  it("accepts an explainable start-queue row and rejects a zero idle threshold in policy", () => {
    const item = CourseStartQueueItemDTO.parse({
      account_id: 8,
      login: "13912345678",
      nickname: "小王",
      account_status: "active",
      source: "purchase",
      entitlement_status: "active",
      progress_percent: 0,
      startup_state: "never_opened",
      idle_hours: 80,
      entitled_at: "2026-09-09 10:00:00",
      last_learning_at: null,
      reminder_count: 0,
      last_reminded_at: null,
      can_remind: true,
      reminder_blocked_reason: null,
    });
    expect(item.startup_state).toBe("never_opened");
    expect(item.can_remind).toBe(true);

    expect(
      CourseStartQueueListDTO.safeParse({
        items: [item],
        total: 1,
        page: 1,
        limit: 20,
        policy: {
          idle_threshold_hours: 0,
          reminder_frequency_hours: 72,
          reminder_cap: 3,
        },
      }).success,
    ).toBe(false);
  });
});
