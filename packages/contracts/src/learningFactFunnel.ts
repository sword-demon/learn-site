import { z } from "zod";

export const LearningFactFunnelWindowDaysSchema = z.union([
  z.literal(7),
  z.literal(30),
  z.literal(90),
]);
export type LearningFactFunnelWindowDays = z.infer<
  typeof LearningFactFunnelWindowDaysSchema
>;

export const LearningFactFunnelSourceSchema = z.enum([
  "all",
  "free",
  "purchase",
  "activation_code",
]);
export type LearningFactFunnelSource = z.infer<
  typeof LearningFactFunnelSourceSchema
>;

export const LearningFactFunnelStageIdSchema = z.enum([
  "entitled",
  "first_opened",
  "valid_progress",
  "completed",
]);
export type LearningFactFunnelStageId = z.infer<
  typeof LearningFactFunnelStageIdSchema
>;

/** Rate is a closed unit interval, or null when the denominator is 0. */
export const FunnelRateSchema = z.number().min(0).max(1).nullable();

export const FunnelStageDTO = z.object({
  id: LearningFactFunnelStageIdSchema,
  label: z.string().min(1),
  count: z.number().int().nonnegative(),
  of_cohort_rate: FunnelRateSchema,
  of_previous_rate: FunnelRateSchema,
});
export type FunnelStageDTO = z.infer<typeof FunnelStageDTO>;

export const FunnelPendingDTO = z.object({
  in_window: z.number().int().nonnegative(),
  in_window_label: z.literal("窗口进行中、尚未开始"),
  window_elapsed: z.number().int().nonnegative(),
  window_elapsed_label: z.literal("窗口内未转化"),
});
export type FunnelPendingDTO = z.infer<typeof FunnelPendingDTO>;

export const FunnelTrialDTO = z.object({
  learners: z.number().int().nonnegative(),
  note: z.literal("未取得课程访问权时打开试看课节的登录学员，不含访客曝光"),
});
export type FunnelTrialDTO = z.infer<typeof FunnelTrialDTO>;

export const FunnelOrderBucketDTO = z.object({
  status: z.string().min(1),
  count: z.number().int().nonnegative(),
});
export type FunnelOrderBucketDTO = z.infer<typeof FunnelOrderBucketDTO>;

export const FunnelOrdersDTO = z.object({
  succeeded: z.number().int().nonnegative(),
  buckets: z.array(FunnelOrderBucketDTO),
  note: z.literal("订单成功不是完成课程"),
});
export type FunnelOrdersDTO = z.infer<typeof FunnelOrdersDTO>;

export const FunnelPublishReachDTO = z.object({
  dispatch_count: z.number().int().nonnegative(),
  recipient_count: z.number().int().nonnegative(),
  entitled_count: z.number().int().nonnegative(),
  note: z.literal("发布触达与已有访问权人数分开，触达不是首次打开课节"),
});
export type FunnelPublishReachDTO = z.infer<typeof FunnelPublishReachDTO>;

export const LearningFactFunnelDisclaimerSchema = z.literal(
  "本报告展示事实转化，不表示增量效果，也不把订单成功或券已使用当成学习完成。",
);

export const LearningFactFunnelDTO = z.object({
  course_id: z.number().int().positive(),
  window_days: LearningFactFunnelWindowDaysSchema,
  source: LearningFactFunnelSourceSchema,
  generated_at: z.string().min(1),
  disclaimer: LearningFactFunnelDisclaimerSchema,
  no_effective_lesson: z.boolean(),
  stages: z.tuple([
    FunnelStageDTO,
    FunnelStageDTO,
    FunnelStageDTO,
    FunnelStageDTO,
  ]),
  pending: FunnelPendingDTO,
  trial: FunnelTrialDTO,
  orders: FunnelOrdersDTO,
  publish_reach: FunnelPublishReachDTO,
});
export type LearningFactFunnelDTO = z.infer<typeof LearningFactFunnelDTO>;

export const LearningFactFunnelQuerySchema = z.object({
  window_days: LearningFactFunnelWindowDaysSchema.default(30),
  source: LearningFactFunnelSourceSchema.default("all"),
});
export type LearningFactFunnelQuery = z.infer<
  typeof LearningFactFunnelQuerySchema
>;
