import { z } from "zod";

export const AdminOrderStatus = z.enum([
  "pending",
  "succeeded",
  "failed",
  "cancelled",
  "unknown",
]);
export type AdminOrderStatus = z.infer<typeof AdminOrderStatus>;

const MoneyAmount = z.number().finite().nonnegative();
const MoneyAmountSnapshot = MoneyAmount.default(0);

export const AdminOrderDTO = z.object({
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  course_title: z.string().nullable(),
  learner_id: z.number().int().positive(),
  department_id: z.number().int().positive().nullable(),
  list_price_snapshot: MoneyAmount,
  sale_price_snapshot: MoneyAmount,
  coupon_discount_snapshot: MoneyAmountSnapshot,
  paid_amount: MoneyAmount,
  learner_coupon_id: z.number().int().positive().nullable().default(null),
  currency: z.string().min(1).max(8),
  status: AdminOrderStatus,
  provider: z.string().min(1),
  provider_ref: z.string().nullable(),
  succeeded_at: z.string().nullable(),
  created_at: z.string().min(1),
  failed_reason: z.string().nullable(),
});
export type AdminOrderDTO = z.infer<typeof AdminOrderDTO>;

export const AdminOrderListDTO = z.object({
  items: z.array(AdminOrderDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type AdminOrderListDTO = z.infer<typeof AdminOrderListDTO>;

// ---------------------------------------------------------------------------
// Learner-facing order DTO (single shape reused by list + detail + post-create
// response). The previous spec left coupon fields implicit; phase 5 surfaces
// them so the checkout UI can display the discount line and the post-submit
// confirmation can show the snapshot.
// ---------------------------------------------------------------------------

export const LearnerOrderDTO = z.object({
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  list_price_snapshot: MoneyAmount,
  sale_price_snapshot: MoneyAmount,
  coupon_discount_snapshot: MoneyAmountSnapshot,
  paid_amount: MoneyAmount,
  learner_coupon_id: z.number().int().positive().nullable().default(null),
  currency: z.string().min(1).max(8),
  status: AdminOrderStatus,
  provider: z.string().min(1),
  succeeded_at: z.string().nullable(),
  created_at: z.string().min(1),
});
export type LearnerOrderDTO = z.infer<typeof LearnerOrderDTO>;

export const LearnerOrderListDTO = z.object({
  items: z.array(LearnerOrderDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive().max(200),
});
export type LearnerOrderListDTO = z.infer<typeof LearnerOrderListDTO>;

// ---------------------------------------------------------------------------
// Create-order input: phase 5 adds the optional learner_coupon_id. Omitting
// the field (or sending null) preserves the existing no-coupon behaviour.
// ---------------------------------------------------------------------------

export const OrderCreateInput = z.object({
  learner_coupon_id: z.number().int().positive().nullable().optional(),
});
export type OrderCreateInput = z.infer<typeof OrderCreateInput>;