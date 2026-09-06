import { z } from 'zod';

/**
 * Ops Inbox wire contracts — shared between apps/admin and apps/api via packages/contracts.
 *
 * Mirror of the canonical pattern in packages/contracts/src/dashboard.ts:
 *   - Zod schema is the single source of truth
 *   - TypeScript types are inferred via z.infer
 *   - Both admin SPA and api response handlers MUST validate at the boundary
 */

// --- enums -------------------------------------------------------------------

export const OpsSourceTypeSchema = z.enum([
  'course_unpublished',
  'map_anomaly',
  'question_pending',
  'feedback_pending',
  'payment_unknown',
  'queue_failed',
  'long_pending',
]);
export type OpsSourceType = z.infer<typeof OpsSourceTypeSchema>;

export const OpsStateSchema = z.enum([
  'open',
  'retrying',
  'snoozed',
  'resolved',
  'assigned',
]);
export type OpsState = z.infer<typeof OpsStateSchema>;

export const OpsSeveritySchema = z.enum(['info', 'warning', 'critical']);
export type OpsSeverity = z.infer<typeof OpsSeveritySchema>;

// --- deep link ---------------------------------------------------------------

export const OpsDeepLinkSchema = z.object({
  name: z.string().min(1).max(64),
  query: z.record(z.union([z.string(), z.number()])).optional(),
});
export type OpsDeepLink = z.infer<typeof OpsDeepLinkSchema>;

// --- impact (per-source shape) ----------------------------------------------

export const OpsImpactSchema = z.object({
  learners: z.number().int().nonnegative(),
  courses: z.number().int().nonnegative().optional(),
  replies: z.number().int().nonnegative().optional(),
  orders_amount_cents: z.number().int().nonnegative().optional(),
  retries: z.number().int().nonnegative().optional(),
  sources: z.record(z.number().int().nonnegative()).optional(),
});
export type OpsImpact = z.infer<typeof OpsImpactSchema>;

// --- single inbox row --------------------------------------------------------

export const OpsExceptionSchema = z.object({
  id: z.string().regex(/^[a-z_]+:\d+$/),
  source_type: OpsSourceTypeSchema,
  source_key: z.string().min(1).max(64),
  title: z.string().min(1).max(200),
  severity: OpsSeveritySchema,
  age_seconds: z.number().int().nonnegative(),
  age_label: z.string().min(1).max(32),
  weight: z.number().int().min(0).max(1000),
  impact: OpsImpactSchema,
  suggested_action: z.string().min(1).max(200),
  deep_link: OpsDeepLinkSchema,
  state: OpsStateSchema,
  assignee_id: z.number().int().positive().nullable(),
  snooze_until: z.string().datetime({ offset: true }).nullable(),
  last_error_code: z.string().max(64).nullable(),
  retry_count: z.number().int().min(0).max(10),
});
export type OpsException = z.infer<typeof OpsExceptionSchema>;

// --- list request ------------------------------------------------------------

export const OpsInboxListRequestSchema = z.object({
  // Filter
  source_type: OpsSourceTypeSchema.optional(),
  state: OpsStateSchema.optional().default('open'),
  age_min_hours: z.number().int().min(0).max(720).optional(),
  // Sort (default: weight DESC, age_seconds DESC)
  sort_by: z.enum(['weight', 'age_seconds']).optional().default('weight'),
  sort_dir: z.enum(['asc', 'desc']).optional().default('desc'),
  // Pagination
  page: z.number().int().min(1).optional().default(1),
  limit: z.number().int().min(1).max(50).optional().default(20),
});
export type OpsInboxListRequest = z.infer<typeof OpsInboxListRequestSchema>;

// --- list response -----------------------------------------------------------

export const OpsInboxListResponseSchema = z.object({
  items: z.array(OpsExceptionSchema),
  total: z.number().int().nonnegative(),
  page: z.number().int().min(1),
  limit: z.number().int().min(1),
  counts_by_source: z.record(z.number().int().nonnegative()),
});
export type OpsInboxListResponse = z.infer<typeof OpsInboxListResponseSchema>;

// --- state transition (POST /:id/transition) -------------------------------

export const OpsTransitionRequestSchema = z.object({
  to_state: OpsStateSchema,
  snooze_until: z.string().datetime({ offset: true }).optional(),
  assignee_id: z.number().int().positive().optional(),
});
export type OpsTransitionRequest = z.infer<typeof OpsTransitionRequestSchema>;

export const OpsTransitionResponseSchema = z.object({
  ok: z.boolean(),
  state: OpsStateSchema,
  updated_at: z.string().datetime({ offset: true }),
});
export type OpsTransitionResponse = z.infer<typeof OpsTransitionResponseSchema>;

// --- retry trigger (POST /queue-failed/:source_key/retry) --------------------

export const OpsRetryResponseSchema = z.object({
  ok: z.boolean(),
  state: OpsStateSchema,
  retry_count: z.number().int().min(0).max(10),
  scheduled_at: z.string().datetime({ offset: true }).nullable(),
});
export type OpsRetryResponse = z.infer<typeof OpsRetryResponseSchema>;

// --- error codes (stable, server-issued) ------------------------------------

export const OPS_ERROR_CODES = {
  OPS_SOURCE_INVALID: 'OPS_SOURCE_INVALID',
  OPS_STATE_INVALID: 'OPS_STATE_INVALID',
  OPS_TRANSITION_FORBIDDEN: 'OPS_TRANSITION_FORBIDDEN',
  OPS_SNOOZE_TOO_SHORT: 'OPS_SNOOZE_TOO_SHORT',
  OPS_SNOOZE_TOO_LONG: 'OPS_SNOOZE_TOO_LONG',
  OPS_ASSIGNEE_INVALID: 'OPS_ASSIGNEE_INVALID',
  OPS_RETRY_NOT_RETRYABLE: 'OPS_RETRY_NOT_RETRYABLE',
  OPS_NOT_FOUND: 'OPS_NOT_FOUND',
  OPS_FORBIDDEN: 'OPS_FORBIDDEN',
} as const;
export type OpsErrorCode = (typeof OPS_ERROR_CODES)[keyof typeof OPS_ERROR_CODES];