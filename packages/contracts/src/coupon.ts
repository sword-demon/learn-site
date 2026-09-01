import { z } from "zod";

// -----------------------------------------------------------------------------
// Shared enums and primitives
// -----------------------------------------------------------------------------

export const CouponScopeType = z.enum(["category", "course", "all"]);
export type CouponScopeType = z.infer<typeof CouponScopeType>;

export const CouponClaimMode = z.enum(["public", "admin_only"]);
export type CouponClaimMode = z.infer<typeof CouponClaimMode>;

export const CouponCampaignStatus = z.enum(["active", "disabled"]);
export type CouponCampaignStatus = z.infer<typeof CouponCampaignStatus>;

export const CouponInstanceStatus = z.enum([
  "unused",
  "locked",
  "used",
  "expired",
  "voided",
]);
export type CouponInstanceStatus = z.infer<typeof CouponInstanceStatus>;

export const CouponSource = z.enum(["claim", "grant"]);
export type CouponSource = z.infer<typeof CouponSource>;

const MoneyAmount = z.number().finite().nonnegative();
const Iso8601 = z.string().min(1);

// -----------------------------------------------------------------------------
// Coupon public (learner claim center)
// -----------------------------------------------------------------------------

export const CouponPublicDTO = z.object({
  id: z.number().int().positive(),
  name: z.string().min(1).max(120),
  scope_type: CouponScopeType,
  scope_summary: z.string().min(1).max(255),
  min_amount: MoneyAmount,
  discount_amount: MoneyAmount,
  claim_starts_at: Iso8601,
  claim_ends_at: Iso8601,
  use_ends_at: Iso8601,
  remaining_quota: z.number().int().nonnegative().nullable(),
});
export type CouponPublicDTO = z.infer<typeof CouponPublicDTO>;

export const CouponPublicListDTO = z.object({
  items: z.array(CouponPublicDTO),
});
export type CouponPublicListDTO = z.infer<typeof CouponPublicListDTO>;

// -----------------------------------------------------------------------------
// Learner coupon instance (my coupons + redemption lists)
// -----------------------------------------------------------------------------

export const LearnerCouponDTO = z.object({
  id: z.number().int().positive(),
  campaign_id: z.number().int().positive(),
  name: z.string().min(1).max(120),
  scope_type: CouponScopeType,
  scope_summary: z.string().min(1).max(255),
  min_amount: MoneyAmount,
  discount_amount: MoneyAmount,
  status: CouponInstanceStatus,
  source: CouponSource,
  expires_at: Iso8601,
  created_at: Iso8601,
  applicable_course_ids: z.array(z.number().int().positive()).optional(),
});
export type LearnerCouponDTO = z.infer<typeof LearnerCouponDTO>;

export const LearnerCouponListDTO = z.object({
  items: z.array(LearnerCouponDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type LearnerCouponListDTO = z.infer<typeof LearnerCouponListDTO>;

// -----------------------------------------------------------------------------
// Checkout coupon option (order preview)
// -----------------------------------------------------------------------------

export const CheckoutCouponOptionDTO = z.object({
  id: z.number().int().positive(),
  name: z.string().min(1).max(120),
  min_amount: MoneyAmount,
  discount_amount: MoneyAmount,
  eligible: z.boolean(),
  ineligible_reason: z.string().nullable(),
  payable_preview: MoneyAmount,
});
export type CheckoutCouponOptionDTO = z.infer<typeof CheckoutCouponOptionDTO>;

export const CheckoutCouponsDTO = z.object({
  base_price: MoneyAmount,
  list_price: MoneyAmount,
  sale_price: MoneyAmount,
  items: z.array(CheckoutCouponOptionDTO),
});
export type CheckoutCouponsDTO = z.infer<typeof CheckoutCouponsDTO>;

// -----------------------------------------------------------------------------
// Admin campaign DTOs
// -----------------------------------------------------------------------------

export const AdminCouponCampaignDTO = z.object({
  id: z.number().int().positive(),
  name: z.string().min(1).max(120),
  scope_type: CouponScopeType,
  scope_category_ids: z.array(z.number().int().positive()),
  scope_course_ids: z.array(z.number().int().positive()),
  min_amount: MoneyAmount,
  discount_amount: MoneyAmount,
  claim_mode: CouponClaimMode,
  claim_starts_at: Iso8601,
  claim_ends_at: Iso8601,
  use_ends_at: Iso8601.nullable(),
  total_quota: z.number().int().nonnegative().nullable(),
  claimed_count: z.number().int().nonnegative(),
  used_count: z.number().int().nonnegative(),
  per_learner_claim_limit: z.number().int().nonnegative(),
  per_learner_use_limit: z.number().int().nonnegative(),
  status: CouponCampaignStatus,
  created_by: z.number().int().positive(),
  created_at: Iso8601,
  updated_at: Iso8601,
});
export type AdminCouponCampaignDTO = z.infer<typeof AdminCouponCampaignDTO>;

export const AdminCouponCampaignListDTO = z.object({
  items: z.array(AdminCouponCampaignDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type AdminCouponCampaignListDTO = z.infer<typeof AdminCouponCampaignListDTO>;

// -----------------------------------------------------------------------------
// Admin redemption DTOs
// -----------------------------------------------------------------------------

export const AdminCouponRedemptionDTO = z.object({
  redemption_id: z.number().int().positive(),
  campaign_id: z.number().int().positive(),
  campaign_name: z.string().min(1).max(120),
  learner_id: z.number().int().positive(),
  learner_masked_phone: z.string().min(1).max(32),
  course_id: z.number().int().positive(),
  course_title: z.string().min(1).max(128),
  order_id: z.number().int().positive(),
  discount_amount: MoneyAmount,
  used_at: Iso8601,
});
export type AdminCouponRedemptionDTO = z.infer<typeof AdminCouponRedemptionDTO>;

export const AdminCouponRedemptionListDTO = z.object({
  items: z.array(AdminCouponRedemptionDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type AdminCouponRedemptionListDTO = z.infer<typeof AdminCouponRedemptionListDTO>;

// -----------------------------------------------------------------------------
// Inputs
// -----------------------------------------------------------------------------

export const CreateCouponInput = z.object({
  name: z.string().min(1).max(120),
  scope_type: CouponScopeType,
  scope_category_ids: z.array(z.number().int().positive()).default([]),
  scope_course_ids: z.array(z.number().int().positive()).default([]),
  min_amount: MoneyAmount,
  discount_amount: MoneyAmount,
  claim_mode: CouponClaimMode,
  claim_starts_at: Iso8601,
  claim_ends_at: Iso8601,
  use_ends_at: Iso8601.nullable().default(null),
  total_quota: z.number().int().nonnegative().nullable().default(null),
  per_learner_claim_limit: z.number().int().nonnegative(),
  per_learner_use_limit: z.number().int().nonnegative(),
});
export type CreateCouponInput = z.infer<typeof CreateCouponInput>;

export const PatchCouponInput = z
  .object({
    name: z.string().min(1).max(120).optional(),
    claim_ends_at: Iso8601.optional(),
    use_ends_at: Iso8601.nullable().optional(),
    total_quota: z.number().int().nonnegative().nullable().optional(),
    expected_updated_at: Iso8601,
  })
  .strict();
export type PatchCouponInput = z.infer<typeof PatchCouponInput>;

export const GrantCouponInput = z.object({
  learner_ids: z.array(z.number().int().positive()).min(1).max(500),
});
export type GrantCouponInput = z.infer<typeof GrantCouponInput>;

export const GrantCouponResultDTO = z.object({
  granted: z.number().int().nonnegative(),
  skipped: z.number().int().nonnegative(),
  items: z.array(LearnerCouponDTO),
});
export type GrantCouponResultDTO = z.infer<typeof GrantCouponResultDTO>;

// -----------------------------------------------------------------------------
// Error code literals — surface stable strings to the front-end.
// -----------------------------------------------------------------------------

export const COUPON_ERROR_CODES = [
  "COUPON_NOT_FOUND",
  "COUPON_RULE_INVALID",
  "COUPON_DATE_INVALID",
  "COUPON_SCOPE_REQUIRED",
  "COUPON_NOT_CLAIMABLE",
  "COUPON_QUOTA_EXCEEDED",
  "COUPON_CLAIM_LIMIT_EXCEEDED",
  "COUPON_ALREADY_CLAIMED",
  "COUPON_NOT_APPLICABLE",
  "COUPON_MIN_AMOUNT_NOT_MET",
  "COUPON_EXPIRED",
  "COUPON_VOIDED",
  "COUPON_ALREADY_USED",
  "COUPON_LOCKED",
  "COUPON_NOT_GRANTABLE",
  "COUPON_VERSION_CONFLICT",
  "ORDER_PENDING_COUPON_MISMATCH",
] as const;
export type CouponErrorCode = (typeof COUPON_ERROR_CODES)[number];