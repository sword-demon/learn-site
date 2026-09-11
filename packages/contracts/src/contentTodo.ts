import { z } from "zod";

export const ContentTodoSourceTypeSchema = z.enum([
  "question_pending",
  "feedback_pending",
]);
export type ContentTodoSourceType = z.infer<typeof ContentTodoSourceTypeSchema>;

export const ContentTodoLabelSchema = z.enum([
  "error",
  "missing_example",
  "resource_problem",
  "other",
]);
export type ContentTodoLabel = z.infer<typeof ContentTodoLabelSchema>;

export const ContentTodoWorkflowStatusSchema = z.enum([
  "untriaged",
  "triaged",
  "awaiting_approval",
  "resolved",
  "closed",
]);
export type ContentTodoWorkflowStatus = z.infer<
  typeof ContentTodoWorkflowStatusSchema
>;

export const ContentTodoTargetSchema = z.object({
  course_id: z.number().int().positive().nullable(),
  chapter_id: z.number().int().positive().nullable(),
  lesson_id: z.number().int().positive().nullable(),
});

export const ContentTodoCandidateSchema = z.object({
  id: z.number().int().positive(),
  version: z.number().int().positive(),
  target_course_id: z.number().int().positive().nullable(),
  target_chapter_id: z.number().int().positive().nullable(),
  target_lesson_id: z.number().int().positive().nullable(),
  target_kind: z.enum([
    "course_intro",
    "lesson_markdown",
    "help_center_candidate",
  ]),
  body: z.string(),
  body_format: z.enum(["html", "markdown", "plain"]),
  base_content_fingerprint: z.string().length(64),
  generator: z.string().min(1).max(32),
  status: z.enum(["draft", "superseded", "approved", "rejected"]),
  generated_by_staff_id: z.number().int().positive().nullable(),
  generated_at: z.string(),
  approved_by_staff_id: z.number().int().positive().nullable(),
  approved_at: z.string().nullable(),
  rejection_reason: z.string().nullable(),
});
export type ContentTodoCandidate = z.infer<typeof ContentTodoCandidateSchema>;

export const ContentTodoAuditSchema = z.object({
  id: z.number().int().positive(),
  action: z.string().min(1),
  actor_id: z.number().int().positive().nullable(),
  payload: z.unknown().nullable(),
  created_at: z.string(),
});

export const ContentTodoSchema = z.object({
  id: z.number().int().positive(),
  source_type: ContentTodoSourceTypeSchema,
  source_key: z.string().min(1).max(64),
  source_course_id: z.number().int().positive(),
  workflow_status: ContentTodoWorkflowStatusSchema,
  label: ContentTodoLabelSchema.nullable(),
  target: ContentTodoTargetSchema,
  first_response_at: z.string().nullable(),
  first_response_kind: z.string().nullable(),
  first_response_confirmed: z.boolean(),
  result_type: z
    .enum([
      "content_updated",
      "help_center_candidate",
      "responded_only",
      "closed_no_change",
    ])
    .nullable(),
  close_reason_code: z
    .enum(["duplicate", "not_actionable", "already_covered", "not_planned"])
    .nullable(),
  close_reason_note: z.string().nullable(),
  resolved_at: z.string().nullable(),
  resolved_by_staff_id: z.number().int().positive().nullable(),
  version: z.number().int().positive(),
  title: z.string(),
  course_title: z.string(),
  age_seconds: z.number().int().nonnegative(),
  age_label: z.string().min(1),
  created_at: z.string(),
  updated_at: z.string(),
});
export type ContentTodo = z.infer<typeof ContentTodoSchema>;

export const ContentTodoDetailSchema = ContentTodoSchema.extend({
  source: z.object({
    source_type: ContentTodoSourceTypeSchema,
    source_key: z.string(),
    course_id: z.number().int().positive(),
    course_title: z.string(),
    chapter_id: z.number().int().positive().nullable(),
    lesson_id: z.number().int().positive().nullable(),
    learner_id: z.number().int().positive(),
    title: z.string(),
    body: z.string(),
    created_at: z.string(),
  }),
  candidates: z.array(ContentTodoCandidateSchema),
  audit: z.array(ContentTodoAuditSchema),
});
export type ContentTodoDetail = z.infer<typeof ContentTodoDetailSchema>;

export const ContentTodoListRequestSchema = z.object({
  source_type: ContentTodoSourceTypeSchema.optional(),
  workflow_status: ContentTodoWorkflowStatusSchema.optional(),
  page: z.number().int().min(1).optional().default(1),
  limit: z.number().int().min(1).max(50).optional().default(20),
});
export type ContentTodoListRequest = z.infer<
  typeof ContentTodoListRequestSchema
>;

export const ContentTodoListResponseSchema = z.object({
  items: z.array(ContentTodoSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type ContentTodoListResponse = z.infer<
  typeof ContentTodoListResponseSchema
>;

export const ContentTodoTriageRequestSchema = z.object({
  label: ContentTodoLabelSchema,
  target_course_id: z.number().int().positive().nullable().optional(),
  target_chapter_id: z.number().int().positive().nullable().optional(),
  target_lesson_id: z.number().int().positive().nullable().optional(),
  expected_version: z.number().int().positive().optional(),
});
export type ContentTodoTriageRequest = z.infer<
  typeof ContentTodoTriageRequestSchema
>;

export const ContentTodoResponseRequestSchema = z.object({
  body: z.string().trim().min(1).max(4000),
});
export type ContentTodoResponseRequest = z.infer<
  typeof ContentTodoResponseRequestSchema
>;

export const ContentTodoCandidateRequestSchema = z.object({
  target_kind: z
    .enum(["course_intro", "lesson_markdown", "help_center_candidate"])
    .optional(),
  body: z.string().max(200000).optional(),
});
export type ContentTodoCandidateRequest = z.infer<
  typeof ContentTodoCandidateRequestSchema
>;

export const ContentTodoCandidateEditRequestSchema = z.object({
  body: z.string().trim().min(1).max(200000),
});
export type ContentTodoCandidateEditRequest = z.infer<
  typeof ContentTodoCandidateEditRequestSchema
>;

export const ContentTodoRejectRequestSchema = z.object({
  reason: z.string().trim().min(1).max(500),
});
export type ContentTodoRejectRequest = z.infer<
  typeof ContentTodoRejectRequestSchema
>;

export const ContentTodoApproveRequestSchema = z.object({
  expected_version: z.number().int().positive().optional(),
  notify_mode: z.enum(["none", "submitter", "enrolled", "both"]).optional(),
});
export type ContentTodoApproveRequest = z.infer<
  typeof ContentTodoApproveRequestSchema
>;

export const ContentTodoCloseRequestSchema = z.object({
  close_reason_code: z.enum([
    "duplicate",
    "not_actionable",
    "already_covered",
    "not_planned",
  ]),
  close_reason_note: z.string().trim().min(1).max(500),
  expected_version: z.number().int().positive().optional(),
});
export type ContentTodoCloseRequest = z.infer<
  typeof ContentTodoCloseRequestSchema
>;

export const ContentTodoErrorCodes = {
  CONTENT_TODO_SOURCE_INVALID: "CONTENT_TODO_SOURCE_INVALID",
  CONTENT_TODO_STATUS_INVALID: "CONTENT_TODO_STATUS_INVALID",
  CONTENT_TODO_LABEL_INVALID: "CONTENT_TODO_LABEL_INVALID",
  CONTENT_TODO_LABEL_REQUIRED: "CONTENT_TODO_LABEL_REQUIRED",
  CONTENT_TODO_VERSION_CONFLICT: "CONTENT_TODO_VERSION_CONFLICT",
  CONTENT_TODO_CONTENT_CHANGED: "CONTENT_TODO_CONTENT_CHANGED",
  CONTENT_TODO_TARGET_CHANGED: "CONTENT_TODO_TARGET_CHANGED",
  CONTENT_TODO_REJECTION_REASON_REQUIRED:
    "CONTENT_TODO_REJECTION_REASON_REQUIRED",
} as const;
