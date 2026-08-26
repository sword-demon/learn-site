import http from './http'
import { z } from 'zod'
import type {
  CategoryDTO,
  CourseDTO,
  ChapterDTO,
  LessonDTO,
  AssetDTO,
  CourseTreeDTO,
  PaginatedCategories,
  PaginatedCourses,
  CreateCategoryInput,
  UpdateCategoryInput,
  UpdateCategoryStatusInput,
  CreateCourseInput,
  UpdateCourseInput,
  CreateChapterInput,
  UpdateChapterInput,
  CreateLessonInput,
  UpdateLessonInput,
  CategoryStatus,
  CourseStatus,
} from '@learn-site/contracts'
import { ApiOk, CategoryDTO as CategorySchema } from '@learn-site/contracts'

/**
 * Admin catalog API wrappers (Phase 4 / T039). All return the unwrapped
 * `data` payload. Errors propagate via axios so views can branch on the
 * response body `{ error: { code, message } }`.
 *
 * Endpoint shapes live in packages/contracts/src/catalog.ts and the API
 * controllers (apps/api/app/controller/admin/{Category,Course,Asset}Controller.php).
 */

export interface CategoryNode extends CategoryDTO {
  children: CategoryNode[]
}

const CategoryIndexEnvelope = ApiOk(z.object({
  tree: z.array(z.unknown()),
  flat: z.array(CategorySchema),
}))
const CategoryEnvelope = ApiOk(CategorySchema)
const CategoryDeleteEnvelope = ApiOk(z.object({ deleted: z.literal(true) }))

export async function listCategoryTree(): Promise<CategoryNode[]> {
  const response = await http.get<unknown>('/categories')
  const payload = CategoryIndexEnvelope.parse(response.data).data
  return buildCategoryTree(payload.flat)
}

function buildCategoryTree(rows: CategoryDTO[]): CategoryNode[] {
  const nodes = new Map<number, CategoryNode>()
  for (const row of rows) {
    nodes.set(row.id, { ...row, children: [] })
  }

  const roots: CategoryNode[] = []
  for (const row of rows) {
    const node = nodes.get(row.id)
    if (!node) continue
    if (row.parent_id === 0) {
      roots.push(node)
      continue
    }
    const parent = nodes.get(row.parent_id)
    if (!parent) {
      throw new Error(`CATEGORY_PARENT_MISSING:${row.id}`)
    }
    parent.children.push(node)
  }
  return roots
}

export interface ListCategoriesParams {
  status?: CategoryStatus
  page?: number
  limit?: number
}

export async function listCategoriesFlat(
  params: ListCategoriesParams = {},
): Promise<PaginatedCategories> {
  const { data } = await http.get<PaginatedCategories>('/categories/flat', {
    params,
  })
  return data
}

export async function createCategory(
  input: CreateCategoryInput,
): Promise<CategoryDTO> {
  const response = await http.post<unknown>('/categories', input)
  return CategoryEnvelope.parse(response.data).data
}

export async function updateCategory(
  id: number,
  input: UpdateCategoryInput,
): Promise<CategoryDTO> {
  const response = await http.patch<unknown>(`/categories/${id}`, input)
  return CategoryEnvelope.parse(response.data).data
}

export async function setCategoryStatus(
  id: number,
  input: UpdateCategoryStatusInput,
): Promise<CategoryDTO> {
  const response = await http.patch<unknown>(
    `/categories/${id}/status`,
    input,
  )
  return CategoryEnvelope.parse(response.data).data
}

export async function deleteCategory(id: number): Promise<void> {
  const response = await http.delete<unknown>(`/categories/${id}`)
  CategoryDeleteEnvelope.parse(response.data)
}

// ─── Courses ─────────────────────────────────────────────────────────

export interface ListCoursesParams {
  status?: CourseStatus
  category_id?: number
  q?: string
  page?: number
  limit?: number
}

export async function listCourses(
  params: ListCoursesParams = {},
): Promise<PaginatedCourses> {
  const { data } = await http.get<PaginatedCourses>('/courses', { params })
  return data
}

export async function getCourseTree(id: number): Promise<CourseTreeDTO> {
  const { data } = await http.get<CourseTreeDTO>(`/courses/${id}`)
  return data
}

export async function createCourse(input: CreateCourseInput): Promise<CourseDTO> {
  const { data } = await http.post<CourseDTO>('/courses', input)
  return data
}

export async function updateCourse(
  id: number,
  input: UpdateCourseInput,
): Promise<CourseDTO> {
  const { data } = await http.patch<CourseDTO>(`/courses/${id}`, input)
  return data
}

export async function publishCourse(id: number): Promise<CourseDTO> {
  const { data } = await http.post<CourseDTO>(`/courses/${id}/publish`)
  return data
}

export async function unpublishCourse(id: number): Promise<CourseDTO> {
  const { data } = await http.post<CourseDTO>(`/courses/${id}/unpublish`)
  return data
}

export async function deleteCourse(id: number): Promise<void> {
  await http.delete(`/courses/${id}`)
}

// ─── Chapters ─────────────────────────────────────────────────────────

export async function listChapters(
  courseId: number,
): Promise<{ items: ChapterDTO[] }> {
  const { data } = await http.get<{ items: ChapterDTO[] }>(
    `/courses/${courseId}/chapters`,
  )
  return data
}

export async function createChapter(
  courseId: number,
  input: CreateChapterInput,
): Promise<ChapterDTO> {
  const { data } = await http.post<ChapterDTO>(
    `/courses/${courseId}/chapters`,
    input,
  )
  return data
}

export async function updateChapter(
  courseId: number,
  chapterId: number,
  input: UpdateChapterInput,
): Promise<ChapterDTO> {
  const { data } = await http.patch<ChapterDTO>(
    `/courses/${courseId}/chapters/${chapterId}`,
    input,
  )
  return data
}

export async function deleteChapter(
  courseId: number,
  chapterId: number,
): Promise<void> {
  await http.delete(`/courses/${courseId}/chapters/${chapterId}`)
}

// ─── Lessons ──────────────────────────────────────────────────────────

export async function listLessons(
  courseId: number,
  chapterId?: number,
): Promise<{ items: LessonDTO[] }> {
  const { data } = await http.get<{ items: LessonDTO[] }>(
    `/courses/${courseId}/lessons`,
    { params: { chapter_id: chapterId } },
  )
  return data
}

export async function createLesson(
  courseId: number,
  input: CreateLessonInput,
): Promise<LessonDTO> {
  const { data } = await http.post<LessonDTO>(
    `/courses/${courseId}/lessons`,
    input,
  )
  return data
}

export async function updateLesson(
  courseId: number,
  lessonId: number,
  input: UpdateLessonInput,
): Promise<LessonDTO> {
  const { data } = await http.patch<LessonDTO>(
    `/courses/${courseId}/lessons/${lessonId}`,
    input,
  )
  return data
}

export async function deleteLesson(
  courseId: number,
  lessonId: number,
): Promise<void> {
  await http.delete(`/courses/${courseId}/lessons/${lessonId}`)
}

// ─── Course covers ────────────────────────────────────────────────────

const CourseCoverUploadSchema = ApiOk(z.object({
  key: z.string().min(1),
  url: z.string().min(1),
  mime_type: z.enum(['image/jpeg', 'image/png', 'image/webp']),
  size_bytes: z.number().int().positive(),
}))

export interface UploadCourseCoverInput {
  file: File
  onUploadProgress?: (event: { loaded: number; total?: number }) => void
}

export type UploadCourseCoverResult = z.infer<typeof CourseCoverUploadSchema>['data']

export async function uploadCourseCover(
  input: UploadCourseCoverInput,
): Promise<UploadCourseCoverResult> {
  const fd = new FormData()
  fd.append('file', input.file)
  const response = await http.post<unknown>('/course-covers', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (event) => {
      if (input.onUploadProgress && typeof event.loaded === 'number') {
        const progress: { loaded: number; total?: number } = { loaded: event.loaded }
        if (event.total !== undefined) progress.total = event.total
        input.onUploadProgress(progress)
      }
    },
  })
  return CourseCoverUploadSchema.parse(response.data).data
}

// ─── Assets ───────────────────────────────────────────────────────────

export interface UploadAssetInput {
  file: File
  kind: 'pdf' | 'video'
  onUploadProgress?: (event: { loaded: number; total?: number }) => void
}

export interface UploadAssetResult {
  id: number
  kind: 'pdf' | 'video'
  storage_path: string
  mime_type: string
  size_bytes: number
  status: AssetDTO['status']
}

export async function uploadAsset(
  input: UploadAssetInput,
): Promise<UploadAssetResult> {
  const fd = new FormData()
  fd.append('file', input.file)
  fd.append('kind', input.kind)
  const { data } = await http.post<UploadAssetResult>('/assets', fd, {
    headers: { 'Content-Type': 'multipart/form-data' },
    onUploadProgress: (e) => {
      if (input.onUploadProgress && typeof e.loaded === 'number') {
        input.onUploadProgress({ loaded: e.loaded })
      }
    },
  })
  return data
}
