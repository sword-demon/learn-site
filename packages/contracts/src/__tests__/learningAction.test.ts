import { describe, expect, it } from "vitest";
import {
  ApiResponse,
  LearnerNextActionDTO,
  LearnerNotificationDTO,
} from "../index";

describe("learning action loop contracts", () => {
  it("parses one server-owned next action", () => {
    const parsed = ApiResponse(LearnerNextActionDTO).parse({
      ok: true,
      data: {
        state: "ready",
        action: {
          type: "continue_lesson",
          priority: 3,
          rule_code: "continue_authorized_lesson",
          reason_code: "CONTINUE_LAST_LESSON",
          title: "继续学习：HTTP 请求生命周期",
          reason: "继续上次未完成的课节",
          target: { resource_type: "lesson", resource_id: 42, path: "/learn/7/42" },
          availability: "available",
          availability_reason: null,
          generated_at: "2026-09-04T10:00:00+08:00",
        },
        fallback: null,
        evaluated_at: "2026-09-04T10:00:00+08:00",
        degraded_dependencies: [],
      },
    });
    expect(parsed.ok).toBe(true);
    if (parsed.ok) expect(parsed.data.action?.target.path).toBe("/learn/7/42");
  });

  it("accepts learning reminders with server-resolved resources", () => {
    const parsed = LearnerNotificationDTO.parse({
      id: 501,
      kind: "learning_reminder",
      title: "优惠券即将到期",
      body: "请及时使用",
      dispatch_id: null,
      resource_type: "coupon",
      resource_id: 88,
      resource_path: "/me/coupons",
      resource_available: true,
      resource_unavailable_reason: null,
      payload: { rule_code: "coupon_expiring", reason_code: "COUPON_EXPIRES_WITHIN_3_LOCAL_DAYS" },
      read: false,
      created_at: "2026-09-04T08:00:00+08:00",
    });
    expect(parsed.kind).toBe("learning_reminder");
    expect(parsed.resource_path).toBe("/me/coupons");
  });
});
