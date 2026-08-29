import { z } from 'zod';

export const CategoryStatus = z.enum(['enabled', 'disabled']);
export type CategoryStatus = z.infer<typeof CategoryStatus>;

export const CategoryDTO = z.object({
  id: z.number().int().positive(),
  parent_id: z.number().int().min(0),
  name: z.string().min(1).max(64),
  path: z.string(),
  depth: z.number().int().min(1).max(3),
  sort: z.number().int().min(0),
  status: CategoryStatus,
  created_at: z.string(),
  updated_at: z.string(),
});
export type CategoryDTO = z.infer<typeof CategoryDTO>;

export const CourseStatus = z.enum(['draft', 'published', 'unpublished']);
export type CourseStatus = z.infer<typeof CourseStatus>;

export const PriceMode = z.enum(['free', 'paid']);
export type PriceMode = z.infer<typeof PriceMode>;

export const LessonContentType = z.enum(['markdown', 'pdf', 'video']);
export type LessonContentType = z.infer<typeof LessonContentType>;

export const LessonStatus = z.enum(['enabled', 'disabled']);
export type LessonStatus = z.infer<typeof LessonStatus>;

export const CourseDTO = z.object({
  id: z.number().int().positive(),
  department_id: z.number().int().positive(),
  category_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  cover_url: z.string().nullable(),
  teacher_name: z.string().min(1).max(64),
  summary: z.string().max(255),
  intro_rich_text: z.string(),
  status: CourseStatus,
  price_mode: PriceMode,
  list_price: z.number().nonnegative(),
  sale_price: z.number().nonnegative(),
  sale_start_at: z.string().nullable(),
  sale_end_at: z.string().nullable(),
  created_by_staff_id: z.number().int().positive(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type CourseDTO = z.infer<typeof CourseDTO>;

export const ChapterDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0),
  status: LessonStatus,
});
export type ChapterDTO = z.infer<typeof ChapterDTO>;

export const LessonDTO = z.object({
  id: z.number().int().positive(),
  chapter_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0),
  status: LessonStatus,
  content_type: LessonContentType,
  body_markdown: z.string().nullable(),
  asset_id: z.number().int().positive().nullable(),
  is_preview: z.boolean(),
  duration_seconds: z.number().int().nonnegative(),
});
export type LessonDTO = z.infer<typeof LessonDTO>;

export const ChapterWithLessonsDTO = ChapterDTO.extend({
  lessons: z.array(LessonDTO),
});
export type ChapterWithLessonsDTO = z.infer<typeof ChapterWithLessonsDTO>;

export const CourseTreeDTO = CourseDTO.extend({
  chapters: z.array(ChapterWithLessonsDTO),
});
export type CourseTreeDTO = z.infer<typeof CourseTreeDTO>;

export const CourseDeletionResult = z.object({
  deleted: z.literal(true),
});
export type CourseDeletionResult = z.infer<typeof CourseDeletionResult>;

export const AssetDTO = z.object({
  id: z.number().int().positive(),
  kind: z.enum(['pdf', 'video']),
  storage_path: z.string(),
  mime_type: z.string(),
  size_bytes: z.number().int().nonnegative(),
  status: z.enum(['processing', 'ready', 'missing', 'broken']),
});
export type AssetDTO = z.infer<typeof AssetDTO>;

export const PaginatedCourses = z.object({
  items: z.array(CourseDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type PaginatedCourses = z.infer<typeof PaginatedCourses>;

export const PaginatedCategories = z.object({
  items: z.array(CategoryDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type PaginatedCategories = z.infer<typeof PaginatedCategories>;

export const CreateCategoryInput = z.object({
  parent_id: z.number().int().min(0),
  name: z.string().min(1).max(64),
  sort: z.number().int().min(0).optional(),
});
export type CreateCategoryInput = z.infer<typeof CreateCategoryInput>;

export const UpdateCategoryInput = z.object({
  name: z.string().min(1).max(64).optional(),
  sort: z.number().int().min(0).optional(),
});
export type UpdateCategoryInput = z.infer<typeof UpdateCategoryInput>;

export const UpdateCategoryStatusInput = z.object({
  status: CategoryStatus,
});
export type UpdateCategoryStatusInput = z.infer<typeof UpdateCategoryStatusInput>;

export const CreateCourseInput = z.object({
  department_id: z.number().int().positive(),
  category_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  cover_url: z.string().url().max(255).optional(),
  teacher_name: z.string().min(1).max(64),
  summary: z.string().max(255),
  intro_rich_text: z.string().min(1),
  price_mode: PriceMode,
  list_price: z.number().nonnegative().optional(),
  sale_price: z.number().nonnegative().optional(),
  sale_start_at: z.string().optional(),
  sale_end_at: z.string().optional(),
});
export type CreateCourseInput = z.infer<typeof CreateCourseInput>;

export const UpdateCourseInput = CreateCourseInput.partial();
export type UpdateCourseInput = z.infer<typeof UpdateCourseInput>;

export const CreateChapterInput = z.object({
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0).optional(),
});
export type CreateChapterInput = z.infer<typeof CreateChapterInput>;

export const UpdateChapterInput = z.object({
  title: z.string().min(1).max(128).optional(),
  sort: z.number().int().min(0).optional(),
  status: LessonStatus.optional(),
});
export type UpdateChapterInput = z.infer<typeof UpdateChapterInput>;

export const CreateLessonInput = z.object({
  chapter_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0).optional(),
  content_type: LessonContentType,
  body_markdown: z.string().optional(),
  asset_id: z.number().int().positive().optional(),
  is_preview: z.boolean().optional(),
  duration_seconds: z.number().int().nonnegative().optional(),
});
export type CreateLessonInput = z.infer<typeof CreateLessonInput>;

export const UpdateLessonInput = z.object({
  title: z.string().min(1).max(128).optional(),
  sort: z.number().int().min(0).optional(),
  status: LessonStatus.optional(),
  content_type: LessonContentType.optional(),
  body_markdown: z.string().optional(),
  asset_id: z.number().int().positive().optional(),
  is_preview: z.boolean().optional(),
  duration_seconds: z.number().int().nonnegative().optional(),
});
export type UpdateLessonInput = z.infer<typeof UpdateLessonInput>;

// ─── Public catalog (Phase 5 / US1) ─────────────────────────────────

export const CourseListItemDTO = z.object({
  id: z.number().int().positive(),
  category_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  cover_url: z.string().nullable(),
  teacher_name: z.string().min(1).max(64),
  summary: z.string().max(255),
  price_mode: PriceMode,
  list_price: z.number().nonnegative(),
  sale_price: z.number().nonnegative(),
  sale_start_at: z.string().nullable(),
  sale_end_at: z.string().nullable(),
  preview_available: z.boolean(),
  learner_count: z.number().int().nonnegative(),
});
export type CourseListItemDTO = z.infer<typeof CourseListItemDTO>;

export const PublicCourseList = z.object({
  items: z.array(CourseListItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type PublicCourseList = z.infer<typeof PublicCourseList>;

export const CategoryBreadcrumbDTO = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  path: z.string(),
  depth: z.number().int().min(1).max(3),
});
export type CategoryBreadcrumbDTO = z.infer<typeof CategoryBreadcrumbDTO>;

export const LessonSummaryDTO = z.object({
  id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0),
  content_type: LessonContentType,
  duration_seconds: z.number().int().nonnegative(),
  is_preview: z.boolean(),
  locked: z.boolean(),
});
export type LessonSummaryDTO = z.infer<typeof LessonSummaryDTO>;

export const ChapterWithLessonSummariesDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  sort: z.number().int().min(0),
  lessons: z.array(LessonSummaryDTO),
});
export type ChapterWithLessonSummariesDTO = z.infer<typeof ChapterWithLessonSummariesDTO>;

export const PublicCourseDTO = z.object({
  id: z.number().int().positive(),
  category_id: z.number().int().positive(),
  category_name: z.string(),
  title: z.string().min(1).max(128),
  cover_url: z.string().nullable(),
  teacher_name: z.string().min(1).max(64),
  summary: z.string().max(255),
  intro_html: z.string(),
  price_mode: PriceMode,
  list_price: z.number().nonnegative(),
  sale_price: z.number().nonnegative(),
  sale_start_at: z.string().nullable(),
  sale_end_at: z.string().nullable(),
  viewer_authorized: z.boolean(),
  viewer_entitlement_status: z.enum(['active', 'revoked']).nullable().default(null),
  viewer_entitlement_source: z.enum(['free', 'purchase']).nullable().default(null),
  viewer_revoked_reason: z.string().nullable().default(null),
  viewer_can_rejoin: z.boolean().default(false),
  learner_count: z.number().int().nonnegative(),
  created_at: z.string(),
});
export type PublicCourseDTO = z.infer<typeof PublicCourseDTO>;

export const PublicCourseDetailDTO = z.object({
  course: PublicCourseDTO,
  chapters: z.array(ChapterWithLessonSummariesDTO),
});
export type PublicCourseDetailDTO = z.infer<typeof PublicCourseDetailDTO>;

export const CategoryCoursesEnvelopeDTO = z.object({
  category: CategoryBreadcrumbDTO,
  list: PublicCourseList,
});
export type CategoryCoursesEnvelopeDTO = z.infer<typeof CategoryCoursesEnvelopeDTO>;

export const LessonDeliveryMarkdownDTO = z.object({
  kind: z.literal('markdown'),
  html: z.string(),
});
export type LessonDeliveryMarkdownDTO = z.infer<typeof LessonDeliveryMarkdownDTO>;

export const LessonDeliveryAssetDTO = z.object({
  kind: z.enum(['pdf', 'video']),
  asset_id: z.number().int().positive(),
  media_url: z.string().startsWith('/api/media/assets/'),
  mime_type: z.string(),
  size_bytes: z.number().int().nonnegative(),
  status: z.enum(['processing', 'ready', 'missing', 'broken']),
});
export type LessonDeliveryAssetDTO = z.infer<typeof LessonDeliveryAssetDTO>;

export const LessonDeliveryDTO = z.discriminatedUnion('kind', [
  LessonDeliveryMarkdownDTO,
  LessonDeliveryAssetDTO,
]);
export type LessonDeliveryDTO = z.infer<typeof LessonDeliveryDTO>;
