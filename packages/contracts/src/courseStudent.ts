import { z } from "zod";

export const CourseStudentDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  nickname: z.string(),
  account_status: z.enum(["active", "disabled"]),
  source: z.enum(["free", "purchase", "activation_code"]),
  entitlement_status: z.enum(["active", "revoked"]),
  progress_percent: z.number().int().min(0).max(100),
  learning_status: z.enum(["not_started", "in_progress", "completed"]),
  last_learning_at: z.string().nullable(),
  completed_at: z.string().nullable(),
  enrolled_at: z.string(),
  revoked_at: z.string().nullable(),
  revoked_reason: z.string().nullable(),
  last_login_at: z.string().nullable(),
});
export type CourseStudentDTO = z.infer<typeof CourseStudentDTO>;

export const CourseStudentListDTO = z.object({
  items: z.array(CourseStudentDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
});
export type CourseStudentListDTO = z.infer<typeof CourseStudentListDTO>;

export const CourseStudentRevokeResultDTO = z.object({
  revoked: z.literal(true),
});
export type CourseStudentRevokeResultDTO = z.infer<
  typeof CourseStudentRevokeResultDTO
>;

export const CourseStudentResetResultDTO = z.object({
  reset: z.literal(true),
});
export type CourseStudentResetResultDTO = z.infer<
  typeof CourseStudentResetResultDTO
>;

export const CourseStartupPolicyDTO = z.object({
  idle_threshold_hours: z.number().int().positive(),
  reminder_frequency_hours: z.number().int().positive(),
  reminder_cap: z.number().int().positive(),
});
export type CourseStartupPolicyDTO = z.infer<typeof CourseStartupPolicyDTO>;

export const CourseStartQueueState = z.enum([
  "never_opened",
  "opened_zero_progress",
]);
export type CourseStartQueueState = z.infer<typeof CourseStartQueueState>;

export const CourseStartReminderBlockReason = z.enum([
  "frequency",
  "cap",
  "not_eligible",
  "started",
  "completed",
  "no_active_entitlement",
  "no_effective_lesson",
  "below_threshold",
]);
export type CourseStartReminderBlockReason = z.infer<
  typeof CourseStartReminderBlockReason
>;

export const CourseStartQueueItemDTO = z.object({
  account_id: z.number().int(),
  login: z.string(),
  nickname: z.string(),
  account_status: z.enum(["active", "disabled"]),
  source: z.enum(["free", "purchase", "activation_code"]),
  entitlement_status: z.literal("active"),
  progress_percent: z.number().int().min(0).max(100),
  startup_state: CourseStartQueueState,
  idle_hours: z.number().int().nonnegative(),
  entitled_at: z.string(),
  last_learning_at: z.string().nullable(),
  reminder_count: z.number().int().nonnegative(),
  last_reminded_at: z.string().nullable(),
  can_remind: z.boolean(),
  reminder_blocked_reason: CourseStartReminderBlockReason.nullable(),
});
export type CourseStartQueueItemDTO = z.infer<typeof CourseStartQueueItemDTO>;

export const CourseStartQueueListDTO = z.object({
  items: z.array(CourseStartQueueItemDTO),
  total: z.number().int(),
  page: z.number().int(),
  limit: z.number().int(),
  policy: CourseStartupPolicyDTO,
});
export type CourseStartQueueListDTO = z.infer<typeof CourseStartQueueListDTO>;

export const SendCourseStartReminderInput = z.object({
  learner_ids: z.array(z.number().int().positive()).min(1),
});
export type SendCourseStartReminderInput = z.infer<
  typeof SendCourseStartReminderInput
>;

export const CourseStartReminderOutcomeDTO = z.object({
  account_id: z.number().int().positive(),
  sent: z.boolean(),
  blocked_reason: CourseStartReminderBlockReason.nullable(),
});
export type CourseStartReminderOutcomeDTO = z.infer<
  typeof CourseStartReminderOutcomeDTO
>;

export const CourseStartReminderResultDTO = z.object({
  sent_count: z.number().int().nonnegative(),
  blocked_count: z.number().int().nonnegative(),
  dispatch_id: z.number().int().positive().nullable(),
  outcomes: z.array(CourseStartReminderOutcomeDTO),
});
export type CourseStartReminderResultDTO = z.infer<
  typeof CourseStartReminderResultDTO
>;
