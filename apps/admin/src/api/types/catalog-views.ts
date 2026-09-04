/**
 * UI-specific types for catalog views.
 *
 * These extend or reshape backend DTOs for frontend consumption.
 * They differ from @learn-site/contracts by:
 * - Guaranteeing required properties (e.g., children always exists as array)
 * - Pre-formatting display values (prices, status labels)
 * - Providing form-friendly structures for editors
 */

import type {
  CategoryDTO as BackendCategoryDTO,
  CourseDTO as BackendCourseDTO,
  CourseTreeDTO as BackendCourseTreeDTO,
  PriceMode,
} from '@learn-site/contracts';

// ─── Category View Types ───────────────────────────────────────

/**
 * Category tree node with recursive children guarantee.
 * I1: children ALWAYS exists, even when empty [].
 */
export interface CategoryNode extends Omit<BackendCategoryDTO, 'children'> {
  children: CategoryNode[]; // Not optional!
}

/**
 * Flattened category row with depth info for table display.
 */
export interface FlatCategoryRow extends Omit<BackendCategoryDTO, 'depth' | 'children'> {
  depth: number; // Explicit depth field for indentation
}

/**
 * Form input shape for category create/edit dialogs.
 */
export interface CategoryFormData {
  id: number | null;
  parent_id: number | null;
  name: string;
  sort: number;
}

// ─── Course View Types ─────────────────────────────────────────

/**
 * Row data for course table/list view.
 * F2: Pre-formatted price and status strings per invariant I2, I4, I5.
 */
export interface CourseTableView {
  id: number;
  department_id: number;
  category_id: number;
  title: string;
  cover_url?: string | null;
  teacher_name: string;
  summary: string;

  // Pre-formatted fields (I4, I5)
  formatted_price: string; // "免费" or "¥199.00" or "¥99.00 / ¥199.00"
  status_label: '草稿' | '已发布' | '已下架';
  status_type: 'info' | 'success' | 'warning';

  // Original fields preserved for operations
  intro_rich_text: string;
  price_mode: PriceMode;
  list_price: number;
  sale_price?: number;
  sale_start_at?: string | null;
  sale_end_at?: string | null;
  created_by_staff_id: number;
  created_at: string;
  updated_at: string;
}

/**
 * Complete course editor form state.
 * F3: Chapter lessons array NEVER undefined.
 */
export interface CourseEditorForm {
  // Course core fields (same as CourseDTO but with optional ID)
  id?: number;
  department_id: number;
  category_id: number;
  title: string;
  cover_url?: string | null;
  teacher_name: string;
  summary: string;
  intro_rich_text: string;
  price_mode: PriceMode;
  list_price: number;
  sale_price?: number;
  sale_start_at?: string | null;
  sale_end_at?: string | null;
  created_by_staff_id?: number; // Only set on update

  // Children guarantees (F3: arrays never undefined)
  chapters: CourseChapterForm[]; // Always array, not optional
}

/**
 * Chapter form with guaranteed lessons array.
 */
export interface CourseChapterForm {
  id?: number;
  course_id: number;
  title: string;
  sort: number;
  status: 'enabled' | 'disabled';
  lessons: CourseLessonForm[]; // Always array, never undefined
}

/**
 * Lesson form with all fields needed for editor.
 */
export interface CourseLessonForm {
  id?: number;
  chapter_id: number;
  title: string;
  sort: number;
  status: 'enabled' | 'disabled';
  content_type: 'markdown' | 'pdf' | 'video';
  body_markdown?: string | null;
  asset_id?: number | null;
  is_preview: boolean;
  duration_seconds: number;
}

/**
 * Wrapped paginated courses with pagination metadata.
 */
export interface CoursePaginationView {
  items: CourseTableView[];
  total: number;
  page: number;
  limit: number;
}

// ─── Mapping Options ───────────────────────────────────────────

/**
 * Optional error handling configuration.
 */
export interface MapOptions {
  /**
   * Strict mode throws on malformed data instead of using defaults.
   * @default false
   */
  strict?: boolean;

  /**
   * Optional logger for non-fatal mapping errors.
   * Used when strict=false to avoid silent failures in production.
   */
  logger?: (error: Error, context: string) => void;
}

// ─── Test Double Types ─────────────────────────────────────────

/**
 * Mock data generator for unit tests.
 * Does NOT call real APIs.
 */
export interface MockCatalogData {
  categories: BackendCategoryDTO[];
  courses: BackendCourseDTO[];
  featuredCourse?: BackendCourseTreeDTO;
}
