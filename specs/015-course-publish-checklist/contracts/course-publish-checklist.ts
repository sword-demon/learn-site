import { z } from 'zod';

const CourseStatus = z.enum(['draft', 'published', 'unpublished']);
const LessonContentType = z.enum(['markdown', 'pdf', 'video']);
const LessonStatus = z.enum(['enabled', 'disabled']);
const PriceMode = z.enum(['free', 'paid']);

export const ChecklistSeverity = z.enum(['hard', 'warning', 'info']);
export type ChecklistSeverity = z.infer<typeof ChecklistSeverity>;

export const ChecklistScope = z.enum(['course', 'chapter', 'lesson']);
export type ChecklistScope = z.infer<typeof ChecklistScope>;

export const ChecklistFindingCode = z.enum([
  'CATEGORY_NOT_FOUND',
  'CATEGORY_DISABLED',
  'INTRO_REQUIRED',
  'SALE_WINDOW_EXPIRED',
  'NO_PUBLISHABLE_CHAPTER',
  'NO_PUBLISHABLE_LESSON',
  'NOTIFICATION_IMPACT_UNAVAILABLE',
  'LESSON_INCOMPLETE',
  'ASSET_PROCESSING',
  'ASSET_MISSING',
  'ASSET_BROKEN',
  'ASSET_UNREACHABLE',
  'ASSET_PROBE_SKIPPED',
  'PROGRESS_DENOMINATOR_CHANGES',
  'NO_TRIAL_LESSON',
  'ALL_LESSONS_TRIAL',
  'MAP_REFERENCE',
  'MAP_STEP_WILL_RECOVER',
  'NOTIFICATION_WILL_SEND',
  'NOTIFICATION_SKIPPED_ALREADY_PUBLISHED',
]);
export type ChecklistFindingCode = z.infer<typeof ChecklistFindingCode>;

export const AssetStatus = z.enum(['processing', 'ready', 'missing', 'broken']);

export const ChecklistFindingDTO = z.object({
  code: ChecklistFindingCode,
  severity: ChecklistSeverity,
  message: z.string().min(1),
  scope: ChecklistScope,
  chapter_id: z.number().int().positive().nullable(),
  lesson_id: z.number().int().positive().nullable(),
});
export type ChecklistFindingDTO = z.infer<typeof ChecklistFindingDTO>;

export const CatalogLessonDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  sort: z.number().int().nonnegative(),
  status: LessonStatus,
  content_type: LessonContentType,
  is_preview: z.boolean(),
  is_effective: z.boolean(),
  asset_id: z.number().int().positive().nullable(),
  asset_status: AssetStatus.nullable(),
});
export type CatalogLessonDTO = z.infer<typeof CatalogLessonDTO>;

export const CatalogChapterDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  sort: z.number().int().nonnegative(),
  status: LessonStatus,
  is_effective_chapter: z.boolean(),
  lessons: z.array(CatalogLessonDTO),
});
export type CatalogChapterDTO = z.infer<typeof CatalogChapterDTO>;

export const CatalogSnapshotDTO = z.object({
  category_id: z.number().int().positive().nullable(),
  category_name: z.string().nullable(),
  category_enabled: z.boolean(),
  intro_present: z.boolean(),
  price_mode: PriceMode,
  price_valid: z.boolean(),
  effective_chapter_count: z.number().int().nonnegative(),
  effective_lesson_count: z.number().int().nonnegative(),
  trial_lesson_count: z.number().int().nonnegative(),
  chapters: z.array(CatalogChapterDTO),
});
export type CatalogSnapshotDTO = z.infer<typeof CatalogSnapshotDTO>;

export const MapImpactItemDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  will_recover_abnormal_step: z.boolean(),
});

export const ImpactSummaryDTO = z.object({
  maps: z.object({
    published_count: z.number().int().nonnegative(),
    draft_count: z.number().int().nonnegative(),
    items: z.array(MapImpactItemDTO),
  }),
  entitlements: z.object({
    active_count: z.number().int().nonnegative(),
  }),
  progress: z.object({
    enrollment_count: z.number().int().nonnegative(),
    current_denominator: z.number().int().nonnegative(),
    next_denominator: z.number().int().nonnegative(),
    will_recalculate: z.boolean(),
    completed_preserved: z.literal(true),
  }),
  notification: z.object({
    will_dispatch: z.boolean(),
    recipient_count: z.number().int().nonnegative().nullable(),
    recipient_unavailable: z.boolean(),
  }),
});
export type ImpactSummaryDTO = z.infer<typeof ImpactSummaryDTO>;

export const PublishChecklistDTO = z.object({
  course_id: z.number().int().positive(),
  course_title: z.string(),
  course_status: CourseStatus,
  generated_at: z.string(),
  content_fingerprint: z.string().min(1),
  hard_error_count: z.number().int().nonnegative(),
  warning_count: z.number().int().nonnegative(),
  can_publish: z.boolean(),
  catalog: CatalogSnapshotDTO,
  findings: z.array(ChecklistFindingDTO),
  impact: ImpactSummaryDTO,
});
export type PublishChecklistDTO = z.infer<typeof PublishChecklistDTO>;

export const PublishCourseInput = z.object({
  acknowledge_warnings: z.boolean().optional().default(false),
});
export type PublishCourseInput = z.infer<typeof PublishCourseInput>;
