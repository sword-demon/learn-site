import { describe, expect, it } from "vitest";
import { AdminOrderDTO, AdminOrderListDTO } from "../order.js";

const order = {
  order_id: 42,
  course_id: 12,
  course_title: "TypeScript 深入实践",
  learner_id: 78,
  department_id: 3,
  list_price_snapshot: 199,
  sale_price_snapshot: 149,
  paid_amount: 149,
  currency: "CNY",
  status: "succeeded",
  provider: "fake",
  provider_ref: "fake-42",
  succeeded_at: "2026-08-25 10:30:00",
  created_at: "2026-08-25 10:29:00",
  failed_reason: null,
};

describe("admin order contract", () => {
  it("accepts an immutable payment snapshot and its paginated list", () => {
    expect(AdminOrderDTO.safeParse(order).success).toBe(true);
    expect(
      AdminOrderListDTO.safeParse({
        items: [order],
        total: 1,
        page: 1,
        limit: 20,
      }).success,
    ).toBe(true);
  });

  it.each([
    { ...order, order_id: 1.2 },
    { ...order, status: "paid" },
    { ...order, paid_amount: -0.01 },
  ])("rejects an invalid order payload", (payload) => {
    expect(AdminOrderDTO.safeParse(payload).success).toBe(false);
  });
});
