import { z } from 'zod';

export const MapStatus = z.enum(['draft', 'published']);
export type MapStatus = z.infer<typeof MapStatus>;

export const MapStageDTO = z.object({
  id: z.number().int().positive(),
  map_id: z.number().int().positive(),
  title: z.string(),
  summary: z.string().nullable(),
  sort_order: z.number().int().nonnegative(),
});
export type MapStageDTO = z.infer<typeof MapStageDTO>;

export const MapCourseStepDTO = z.object({
  map_stage_course_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  sort_order: z.number().int().nonnegative(),
  available: z.boolean(),
  viewer_authorized: z.boolean(),
  completed: z.boolean(),
  course: z.object({
    id: z.number().int().positive(),
    title: z.string(),
    teacher_name: z.string(),
    cover_url: z.string().nullable(),
    status: z.string(),
  }).nullable(),
});
export type MapCourseStepDTO = z.infer<typeof MapCourseStepDTO>;

export const MapStageWithCoursesDTO = MapStageDTO.extend({
  courses: z.array(MapCourseStepDTO),
});
export type MapStageWithCoursesDTO = z.infer<typeof MapStageWithCoursesDTO>;

export const MapSummaryDTO = z.object({
  id: z.number().int().positive(),
  department_id: z.number().int().positive(),
  title: z.string(),
  summary: z.string().nullable(),
  cover_url: z.string().nullable(),
  objective: z.string().nullable(),
  audience: z.string().nullable(),
  status: MapStatus,
  created_at: z.string(),
  updated_at: z.string(),
});
export type MapSummaryDTO = z.infer<typeof MapSummaryDTO>;

export const MapEnrollmentDTO = z.object({
  enrolled_at: z.string(),
  completed_courses: z.number().int().nonnegative(),
  total_courses: z.number().int().nonnegative(),
  progress_percent: z.number().int().min(0).max(100),
  completed_at: z.string().nullable(),
});
export type MapEnrollmentDTO = z.infer<typeof MapEnrollmentDTO>;

export const MapPublishIssueCode = z.enum([
  'MAP_HAS_NO_STAGES',
  'STAGE_HAS_NO_COURSES',
  'MAP_HAS_UNPUBLISHED_COURSE',
]);
export type MapPublishIssueCode = z.infer<typeof MapPublishIssueCode>;

export const MapPublishIssueDTO = z.object({
  code: MapPublishIssueCode,
  stage_id: z.number().int().positive().nullable(),
  course_id: z.number().int().positive().nullable(),
});
export type MapPublishIssueDTO = z.infer<typeof MapPublishIssueDTO>;

export const MapNextStepDTO = z.object({
  map_stage_course_id: z.number().int().positive(),
  stage_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
});
export type MapNextStepDTO = z.infer<typeof MapNextStepDTO>;

export const AdminMapDetailDTO = MapSummaryDTO.extend({
  stages: z.array(MapStageWithCoursesDTO),
  publish_issues: z.array(MapPublishIssueDTO),
});
export type AdminMapDetailDTO = z.infer<typeof AdminMapDetailDTO>;

export const LearnerMapDetailDTO = MapSummaryDTO.extend({
  stages: z.array(MapStageWithCoursesDTO),
  enrollment: MapEnrollmentDTO.nullable(),
  next_step: MapNextStepDTO.nullable(),
});
export type LearnerMapDetailDTO = z.infer<typeof LearnerMapDetailDTO>;

export const AdminMapListDTO = z.object({
  items: z.array(MapSummaryDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type AdminMapListDTO = z.infer<typeof AdminMapListDTO>;

export const LearnerMapListDTO = z.object({
  items: z.array(MapSummaryDTO.extend({
    enrollment: MapEnrollmentDTO.nullable(),
  })),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type LearnerMapListDTO = z.infer<typeof LearnerMapListDTO>;

// Compatibility aliases for callers outside the two first-party SPAs.
export const MapDetailDTO = LearnerMapDetailDTO;
export type MapDetailDTO = z.infer<typeof MapDetailDTO>;
export const MapListDTO = LearnerMapListDTO;
export type MapListDTO = z.infer<typeof MapListDTO>;

export const CreateMapInput = z.object({
  department_id: z.number().int().positive(),
  title: z.string().min(1).max(128),
  summary: z.string().max(255).nullable().optional(),
  cover_url: z.string().url().max(255).nullable().optional(),
  objective: z.string().nullable().optional(),
  audience: z.string().nullable().optional(),
});
export type CreateMapInput = z.infer<typeof CreateMapInput>;

export const UpdateMapInput = z.object({
  title: z.string().min(1).max(128).optional(),
  summary: z.string().max(255).nullable().optional(),
  cover_url: z.string().url().max(255).nullable().optional(),
  objective: z.string().nullable().optional(),
  audience: z.string().nullable().optional(),
  department_id: z.number().int().positive().optional(),
});
export type UpdateMapInput = z.infer<typeof UpdateMapInput>;

export const CreateStageInput = z.object({
  title: z.string().min(1).max(128),
  summary: z.string().max(255).nullable().optional(),
});
export type CreateStageInput = z.infer<typeof CreateStageInput>;

export const UpdateStageInput = z.object({
  title: z.string().min(1).max(128).optional(),
  summary: z.string().max(255).nullable().optional(),
  sort_order: z.number().int().positive().optional(),
});
export type UpdateStageInput = z.infer<typeof UpdateStageInput>;

export const AddCourseToStageInput = z.object({
  course_id: z.number().int().positive(),
});
export type AddCourseToStageInput = z.infer<typeof AddCourseToStageInput>;
