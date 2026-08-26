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

export const AdminOrderDTO = z.object({
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  course_title: z.string().nullable(),
  learner_id: z.number().int().positive(),
  department_id: z.number().int().positive().nullable(),
  list_price_snapshot: MoneyAmount,
  sale_price_snapshot: MoneyAmount,
  paid_amount: MoneyAmount,
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
