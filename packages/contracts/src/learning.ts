import { z } from 'zod';

// ─── Phase 6 / US3 — entitlement, progress, orders ─────────────────

export const OrderStatus = z.enum(['pending', 'succeeded', 'failed', 'cancelled', 'unknown']);
export type OrderStatus = z.infer<typeof OrderStatus>;

// POST /courses/{id}/start
export const StartCourseResponseDTO = z.object({
  course_id: z.number().int().positive(),
  entitled: z.boolean(),
  source: z.enum(['free', 'purchase', 'activation_code']),
  price_mode: z.enum(['free', 'paid']),
  first_lesson: z.object({
    id: z.number().int().positive(),
    title: z.string(),
    content_type: z.enum(['markdown', 'pdf', 'video']),
    is_preview: z.boolean(),
  }).nullable(),
});
export type StartCourseResponseDTO = z.infer<typeof StartCourseResponseDTO>;

// GET /my/learning
export const MyLearningCourseSummaryDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  cover_url: z.string().nullable(),
  teacher_name: z.string(),
  status: z.enum(['draft', 'published', 'unpublished']),
  price_mode: z.enum(['free', 'paid']),
});
export type MyLearningCourseSummaryDTO = z.infer<typeof MyLearningCourseSummaryDTO>;

export const MyLearningItemDTO = z.object({
  course_id: z.number().int().positive(),
  progress_percent: z.number().int().min(0).max(100),
  last_lesson_id: z.number().int().positive().nullable(),
  last_position: z.number().int().nonnegative(),
  completed_at: z.string().nullable(),
  updated_at: z.string(),
  entitlement_status: z.enum(['active', 'revoked']),
  entitlement_source: z.enum(['free', 'purchase', 'activation_code']),
  revoked_at: z.string().nullable(),
  revoked_reason: z.string().nullable(),
  can_rejoin: z.boolean(),
  course: MyLearningCourseSummaryDTO,
});
export type MyLearningItemDTO = z.infer<typeof MyLearningItemDTO>;

export const MyLearningListDTO = z.object({
  items: z.array(MyLearningItemDTO),
});
export type MyLearningListDTO = z.infer<typeof MyLearningListDTO>;

// POST /lessons/{id}/progress
export const LessonProgressReportDTO = z.object({
  content_type: z.enum(['markdown', 'pdf', 'video']),
  position_seconds: z.number().int().nonnegative(),
  duration_seconds: z.number().int().nonnegative().optional(),
  completed: z.boolean().optional(),
});
export type LessonProgressReportDTO = z.infer<typeof LessonProgressReportDTO>;

export const LessonProgressDTO = z.object({
  lesson_id: z.number().int().positive(),
  position_seconds: z.number().int().nonnegative(),
  completed: z.boolean(),
  completed_at: z.string().nullable(),
  opened_at: z.string().nullable(),
});
export type LessonProgressDTO = z.infer<typeof LessonProgressDTO>;

// POST /courses/{id}/orders
export const PaymentEnvelopeDTO = z.object({
  type: z.string(),
  code_url: z.string(),
  out_trade_no: z.string().optional(),
  amount: z.number().optional(),
  currency: z.string().optional(),
  provider: z.string().optional(),
});
export type PaymentEnvelopeDTO = z.infer<typeof PaymentEnvelopeDTO>;

export const CreateOrderResponseDTO = z.object({
  order_id: z.number().int().positive(),
  status: z.literal('pending'),
  list_price_snapshot: z.number().nonnegative(),
  sale_price_snapshot: z.number().nonnegative(),
  coupon_discount_snapshot: z.number().nonnegative().default(0),
  paid_amount: z.number().nonnegative(),
  learner_coupon_id: z.number().int().positive().nullable().default(null),
  payment: PaymentEnvelopeDTO,
});
export type CreateOrderResponseDTO = z.infer<typeof CreateOrderResponseDTO>;

// GET /orders, GET /orders/{id}
export const OrderDTO = z.object({
  order_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  course_title: z.string().nullable().default(null),
  list_price_snapshot: z.number().nonnegative(),
  sale_price_snapshot: z.number().nonnegative(),
  coupon_discount_snapshot: z.number().nonnegative().default(0),
  paid_amount: z.number().nonnegative(),
  learner_coupon_id: z.number().int().positive().nullable().default(null),
  currency: z.string(),
  status: OrderStatus,
  provider: z.string(),
  succeeded_at: z.string().nullable(),
  created_at: z.string(),
});
export type OrderDTO = z.infer<typeof OrderDTO>;

export const OrderListDTO = z.object({
  items: z.array(OrderDTO),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
  total: z.number().int().nonnegative(),
});
export type OrderListDTO = z.infer<typeof OrderListDTO>;
