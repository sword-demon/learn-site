import { describe, expect, it } from "vitest";
import {
  CheckoutCouponOptionDTO,
  COUPON_ERROR_CODES,
  CouponErrorCode,
  CouponPublicDTO,
  CreateCouponInput,
  GrantCouponInput,
  LearnerCouponDTO,
  PatchCouponInput,
} from "../coupon";

describe("coupon contracts", () => {
  it("parses a CouponPublicDTO shape", () => {
    const parsed = CouponPublicDTO.parse({
      id: 1,
      name: "满 50 减 10",
      scope_type: "all",
      scope_summary: "无门槛",
      min_amount: 0,
      discount_amount: 10,
      claim_starts_at: "2026-09-01T00:00:00+08:00",
      claim_ends_at: "2026-09-30T23:59:59+08:00",
      use_ends_at: "2026-09-30T23:59:59+08:00",
      remaining_quota: 100,
    });
    expect(parsed.id).toBe(1);
    expect(parsed.scope_type).toBe("all");
  });

  it("rejects negative discount amount", () => {
    expect(() =>
      CheckoutCouponOptionDTO.parse({
        id: 1,
        name: "bad",
        min_amount: 0,
        discount_amount: -1,
        eligible: true,
        ineligible_reason: null,
        payable_preview: 100,
      }),
    ).toThrow();
  });

  it("CreateCouponInput defaults scope lists to empty", () => {
    const parsed = CreateCouponInput.parse({
      name: "x",
      scope_type: "all",
      min_amount: 0,
      discount_amount: 10,
      claim_mode: "public",
      claim_starts_at: "2026-09-01T00:00:00+08:00",
      claim_ends_at: "2026-09-30T23:59:59+08:00",
      use_ends_at: null,
      total_quota: null,
      per_learner_claim_limit: 1,
      per_learner_use_limit: 1,
    });
    expect(parsed.scope_category_ids).toEqual([]);
    expect(parsed.scope_course_ids).toEqual([]);
  });

  it("PatchCouponInput requires expected_updated_at", () => {
    expect(() =>
      PatchCouponInput.parse({
        name: "x",
      }),
    ).toThrow();
  });

  it("GrantCouponInput rejects empty learner_ids", () => {
    expect(() => GrantCouponInput.parse({ learner_ids: [] })).toThrow();
  });

  it("LearnerCouponDTO rejects unknown status", () => {
    expect(() =>
      LearnerCouponDTO.parse({
        id: 1,
        campaign_id: 1,
        name: "x",
        scope_type: "all",
        scope_summary: "x",
        min_amount: 0,
        discount_amount: 1,
        status: "bogus",
        source: "claim",
        expires_at: "2026-09-30T23:59:59+08:00",
        created_at: "2026-09-01T00:00:00+08:00",
      }),
    ).toThrow();
  });

  it("COUPON_ERROR_CODES lists stable strings", () => {
    const sample: CouponErrorCode = "COUPON_MIN_AMOUNT_NOT_MET";
    expect(COUPON_ERROR_CODES).toContain(sample);
  });
});