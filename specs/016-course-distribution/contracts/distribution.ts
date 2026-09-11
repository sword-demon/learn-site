// packages/contracts/src/distribution.ts
//
// 016-course-distribution — frontend/backend shared schemas (Zod).
//
// Each shape mirrors a single API response or input. Money is integer cents;
// phone numbers are masked at the service boundary so no DTO carries plaintext.
//
// Forbidden in any DTO: plaintext phone, plaintext short code, plaintext
// referrer nickname, full referral chain beyond the receiver's own window.

import { z } from "zod";

// ---------------------------------------------------------------------------
// Enums
// ---------------------------------------------------------------------------

export const CommissionLevel = z.union([z.literal(1), z.literal(2), z.literal(3)]);
export type CommissionLevel = z.infer<typeof CommissionLevel>;

export const CommissionStatus = z.enum([
  "pending",
  "settled",
  "voided",
  "pending_blocked",
]);
export type CommissionStatus = z.infer<typeof CommissionStatus>;

export const CommissionSource = z.enum([
  "system_settle",
  "system_refund_void",
  "admin_void",
]);
export type CommissionSource = z.infer<typeof CommissionSource>;

export const DistributionPayoutForm = z.literal("cash_record_only");
export type DistributionPayoutForm = z.infer<typeof DistributionPayoutForm>;

export const DistributionSettlement = z.literal(
  "order_settled_after_refund_window",
);
export type DistributionSettlement = z.infer<typeof DistributionSettlement>;

export const DistributionRefundVoidRule = z.literal("void_all");
export type DistributionRefundVoidRule = z.infer<typeof DistributionRefundVoidRule>;

export const DistributionBase = z.enum([
  "order_paid",
  "list_price",
  "sale_price",
]);
export type DistributionBase = z.infer<typeof DistributionBase>;

export const ShareEntryScope = z.enum(["course", "site"]);
export type ShareEntryScope = z.infer<typeof ShareEntryScope>;

export const DistributionAuditAction = z.enum([
  "config.update",
  "course.override.update",
  "commission.settle",
  "commission.void_admin",
  "commission.void_refund",
]);
export type DistributionAuditAction = z.infer<typeof DistributionAuditAction>;

// ---------------------------------------------------------------------------
// Site distribution config (stored as site_settings key='distribution_config')
// ---------------------------------------------------------------------------

export const DistributionConfigDTO = z.object({
  enabled: z.boolean(),
  level_cap: z.union([z.literal(1), z.literal(2), z.literal(3)]), // FR-010 / SC-003
  level1_pct: z.number().min(0).max(1),
  level2_pct: z.number().min(0).max(1),
  level3_pct: z.number().min(0).max(1),
  base: DistributionBase,
  per_order_cap_cents: z.number().int().nonnegative(),
  per_learner_course_cap_cents: z.number().int().nonnegative(),
  per_learner_total_cap_cents: z.number().int().nonnegative().nullable(),
  settlement: DistributionSettlement,
  refund_void_rule: DistributionRefundVoidRule,
  payout_form: DistributionPayoutForm,
  learner_can_view_detail: z.boolean(),
  updated_at: z.string().min(1),
  updated_by: z.number().int().positive(),
});
export type DistributionConfigDTO = z.infer<typeof DistributionConfigDTO>;

export const DistributionConfigUpdateInput = DistributionConfigDTO.omit({
  updated_at: true,
  updated_by: true,
});
export type DistributionConfigUpdateInput = z.infer<typeof DistributionConfigUpdateInput>;

// ---------------------------------------------------------------------------
// Course-level override
// ---------------------------------------------------------------------------

export const DistributionCourseOverrideDTO = z.object({
  course_id: z.number().int().positive(),
  enabled: z.boolean(),
  level1_pct: z.number().min(0).max(1).nullable(),
  level2_pct: z.number().min(0).max(1).nullable(),
  level3_pct: z.number().min(0).max(1).nullable(),
  per_order_cap_cents: z.number().int().nonnegative().nullable(),
  per_learner_course_cap_cents: z.number().int().nonnegative().nullable(),
  updated_at: z.string().min(1),
  updated_by: z.number().int().positive(),
});
export type DistributionCourseOverrideDTO = z.infer<typeof DistributionCourseOverrideDTO>;

export const DistributionCourseOverrideUpsertInput =
  DistributionCourseOverrideDTO.omit({
    course_id: true,
    updated_at: true,
    updated_by: true,
  });
export type DistributionCourseOverrideUpsertInput = z.infer<typeof DistributionCourseOverrideUpsertInput>;

// ---------------------------------------------------------------------------
// Share entry (learner-generated)
// ---------------------------------------------------------------------------

export const ShareEntryDTO = z.object({
  id: z.number().int().positive(),
  scope: ShareEntryScope,
  course_id: z.number().int().positive().nullable(),
  masked_code: z.string().regex(/^[A-Z2-9]{4}\*{4}[A-Z2-9]{4}$/),
  created_at: z.string().min(1),
  distribution_enabled_at_creation: z.boolean(),
  revoked_at: z.string().nullable(),
  visit_count: z.number().int().nonnegative(),
  bound_count: z.number().int().nonnegative(),
});
export type ShareEntryDTO = z.infer<typeof ShareEntryDTO>;

export const ShareEntryListDTO = z.object({
  items: z.array(ShareEntryDTO),
});
export type ShareEntryListDTO = z.infer<typeof ShareEntryListDTO>;

// Returned ONCE on creation; never retrievable again.
export const ShareEntryCreateOutput = z.object({
  id: z.number().int().positive(),
  plaintext_code: z.string().min(8).max(32),
  share_url: z.string().url(),
  created_at: z.string().min(1),
});
export type ShareEntryCreateOutput = z.infer<typeof ShareEntryCreateOutput>;

export const ShareEntryCreateInput = z.object({
  scope: ShareEntryScope,
  course_id: z.number().int().positive().optional(),
});
export type ShareEntryCreateInput = z.infer<typeof ShareEntryCreateInput>;

// ---------------------------------------------------------------------------
// Commission records (learner view + admin reconcile)
// ---------------------------------------------------------------------------

const MaskedPhone = z.string().regex(/^1[3-9]\d\*{4}\d{4}$/);

export const CommissionRecordDTO = z.object({
  id: z.number().int().positive(),
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  course_title: z.string().min(1),
  referee_masked_phone: MaskedPhone,
  level: CommissionLevel,
  amount_cents: z.number().int().nonnegative(),
  status: CommissionStatus,
  source: CommissionSource,
  created_at: z.string().min(1),
  settled_at: z.string().nullable(),
  voided_at: z.string().nullable(),
  void_reason: z.string().nullable(),
});
export type CommissionRecordDTO = z.infer<typeof CommissionRecordDTO>;

export const CommissionListDTO = z.object({
  items: z.array(CommissionRecordDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
  summary: z.object({
    pending_cents: z.number().int().nonnegative(),
    settled_cents: z.number().int().nonnegative(),
    voided_cents: z.number().int().nonnegative(),
    total_cents: z.number().int().nonnegative(),
  }),
});
export type CommissionListDTO = z.infer<typeof CommissionListDTO>;

// ---------------------------------------------------------------------------
// Downline view (learner)
// ---------------------------------------------------------------------------

export const DownlineEntryDTO = z.object({
  learner_id: z.number().int().positive(),
  masked_phone: MaskedPhone,
  level: CommissionLevel,
  registered_at: z.string().min(1),
});
export type DownlineEntryDTO = z.infer<typeof DownlineEntryDTO>;

export const DownlineListDTO = z.object({
  items: z.array(DownlineEntryDTO),
});
export type DownlineListDTO = z.infer<typeof DownlineListDTO>;

// ---------------------------------------------------------------------------
// Admin reconcile — by order
// ---------------------------------------------------------------------------

export const AdminCommissionByOrderDTO = z.object({
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  order_paid_cents_snapshot: z.number().int().nonnegative(),
  config_snapshot: DistributionConfigDTO,
  receivers: z.array(
    z.object({
      referrer_learner_id: z.number().int().positive(),
      referrer_masked_phone: MaskedPhone,
      level: CommissionLevel,
      amount_cents: z.number().int().nonnegative(),
      status: CommissionStatus,
      source: CommissionSource,
      settled_at: z.string().nullable(),
      voided_at: z.string().nullable(),
      void_reason: z.string().nullable(),
    }),
  ),
});
export type AdminCommissionByOrderDTO = z.infer<typeof AdminCommissionByOrderDTO>;

// ---------------------------------------------------------------------------
// Admin audit
// ---------------------------------------------------------------------------

export const DistributionAuditDTO = z.object({
  id: z.number().int().positive(),
  actor_type: z.enum(["admin", "system"]),
  actor_id: z.number().int().positive().nullable(),
  action: DistributionAuditAction,
  subject_type: z.enum(["config", "course_override", "commission", "share_entry"]),
  subject_id: z.number().int().positive().nullable(),
  before_json: z.unknown().nullable(),
  after_json: z.unknown().nullable(),
  reason: z.string().nullable(),
  created_at: z.string().min(1),
});
export type DistributionAuditDTO = z.infer<typeof DistributionAuditDTO>;

export const DistributionAuditListDTO = z.object({
  items: z.array(DistributionAuditDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type DistributionAuditListDTO = z.infer<typeof DistributionAuditListDTO>;

// ---------------------------------------------------------------------------
// Admin manual void
// ---------------------------------------------------------------------------

export const CommissionVoidInput = z.object({
  reason: z.string().trim().min(5).max(500),
});
export type CommissionVoidInput = z.infer<typeof CommissionVoidInput>;
