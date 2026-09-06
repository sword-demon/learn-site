import { describe, expect, it } from "vitest";
import {
  OpsInboxListResponseSchema,
  OpsSourceTypeSchema,
  OpsTransitionRequestSchema,
} from "../src/opsInbox";

describe("ops inbox contracts", () => {
  it("parses a valid list response", () => {
    const row = {
      id: "payment_unknown:1",
      source_type: "payment_unknown",
      source_key: "1",
      title: "订单 #1 支付状态未知",
      severity: "critical",
      age_seconds: 60,
      age_label: "1 分钟",
      weight: 100,
      impact: { learners: 1, orders_amount_cents: 100 },
      suggested_action: "核对支付回调并处理订单",
      deep_link: { name: "orders", query: { status: "unknown" } },
      state: "open",
      assignee_id: null,
      snooze_until: null,
      last_error_code: null,
      retry_count: 0,
    };
    expect(
      OpsInboxListResponseSchema.parse({
        items: [row],
        total: 1,
        page: 1,
        limit: 20,
        counts_by_source: { payment_unknown: 1 },
      }).items,
    ).toHaveLength(1);
  });

  it("rejects an unknown source type", () => {
    expect(() => OpsSourceTypeSchema.parse("unknown")).toThrow();
  });

  it("parses a snooze transition", () => {
    expect(
      OpsTransitionRequestSchema.parse({
        to_state: "snoozed",
        snooze_until: "2026-09-06T18:00:00+08:00",
      }).to_state,
    ).toBe("snoozed");
  });

  it("rejects an invalid target state", () => {
    expect(() =>
      OpsTransitionRequestSchema.parse({ to_state: "bad" }),
    ).toThrow();
  });
});
