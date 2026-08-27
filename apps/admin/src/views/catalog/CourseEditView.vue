<template>
  <section class="page">
    <header class="bar">
      <div>
        <el-button
          link
          @click="goBack"
        >
          ← 返回课程列表
        </el-button>
        <h2 class="title">
          {{ isNew ? '新建课程' : `编辑：${form.title || `#${courseId}`}` }}
        </h2>
      </div>
      <div class="actions">
        <el-button
          :disabled="isNew"
          @click="goPreview"
        >
          预览
        </el-button>
        <el-button
          type="primary"
          :loading="saving"
          @click="saveDraft"
        >
          保存草稿
        </el-button>
        <el-button
          type="success"
          :loading="publishing"
          :disabled="isNew"
          @click="onPublish"
        >
          发布
        </el-button>
      </div>
    </header>

    <el-alert
      v-if="status === 'error'"
      :title="errorMessage"
      type="error"
      show-icon
      :closable="false"
    />

    <el-tabs
      v-model="activeTab"
      class="tabs"
    >
      <el-tab-pane
        label="基本信息"
        name="basic"
      >
        <el-form
          :model="form"
          label-position="top"
          class="form-grid"
        >
          <el-form-item
            label="标题"
            required
          >
            <el-input
              v-model="form.title"
              maxlength="128"
              placeholder="1–128 字"
            />
          </el-form-item>
          <el-form-item label="课程封面">
            <CourseCoverUpload
              v-model="form.cover_url"
            />
          </el-form-item>
          <el-form-item
            label="所属部门"
            required
          >
            <el-select
              v-model="form.department_id"
              :disabled="isNew === false"
              placeholder="请选择所属部门"
              style="width: 100%;"
            >
              <el-option
                v-for="d in departmentOptions"
                :key="d.id"
                :label="d.name"
                :value="d.id"
              />
            </el-select>
          </el-form-item>
          <p
            v-if="departmentOptions.length === 0"
            class="field-hint span-2"
          >
            暂无启用部门，请先在组织管理 → 部门管理创建。
          </p>
          <el-form-item
            label="讲师"
            required
          >
            <el-input
              v-model="form.teacher_name"
              maxlength="64"
              placeholder="1–64 字"
            />
          </el-form-item>
          <el-form-item
            label="所属分类"
            required
          >
            <!-- 新建时 category_id 为空，展示占位文案而非数字 0 -->
            <el-tree-select
              v-model="form.category_id"
              :data="categoryOptions"
              :props="{ label: 'name', value: 'id', children: 'children' }"
              check-strictly
              clearable
              placeholder="请选择所属分类"
              style="width: 100%;"
            />
          </el-form-item>
          <el-form-item
            label="简介"
            class="span-2"
          >
            <el-input
              v-model="form.summary"
              maxlength="255"
              placeholder="≤255 字"
            />
          </el-form-item>
          <el-form-item
            label="富文本简介 (HTML)"
            class="span-2"
            required
          >
            <el-input
              v-model="form.intro_rich_text"
              type="textarea"
              :rows="6"
              placeholder="支持简单 HTML，保存与发布前服务器会做白名单清洗。"
            />
          </el-form-item>
          <el-form-item label="价格模式">
            <el-radio-group v-model="form.price_mode">
              <el-radio-button value="free">
                免费
              </el-radio-button>
              <el-radio-button value="paid">
                付费
              </el-radio-button>
            </el-radio-group>
          </el-form-item>
          <el-form-item
            v-if="form.price_mode === 'paid'"
            label="列表价"
          >
            <el-input-number
              v-model="form.list_price"
              :min="0"
              :max="99999.99"
              :precision="2"
            />
          </el-form-item>
          <el-form-item
            v-if="form.price_mode === 'paid'"
            label="销售价（可空）"
          >
            <el-input-number
              v-model="form.sale_price"
              :min="0"
              :max="99999.99"
              :precision="2"
            />
          </el-form-item>
          <el-form-item
            v-if="form.price_mode === 'paid' && Number(form.sale_price) > 0"
            label="销售时段"
            class="span-2"
          >
            <el-date-picker
              v-model="saleRange"
              type="datetimerange"
              range-separator="至"
              start-placeholder="开始"
              end-placeholder="结束"
              value-format="YYYY-MM-DD HH:mm:ss"
              style="width: 100%;"
            />
          </el-form-item>
        </el-form>
      </el-tab-pane>

      <el-tab-pane
        label="章节与课节"
        name="structure"
      >
        <div class="structure">
          <aside class="chapters">
            <div class="chapter-bar">
              <h3>章节</h3>
              <el-button
                size="small"
                type="primary"
                :disabled="isNew"
                @click="openChapterDialog(null)"
              >
                新增
              </el-button>
            </div>
            <ul>
              <li
                v-for="ch in tree.chapters ?? []"
                :key="ch.id"
                :class="{ active: selectedChapterId === ch.id }"
                @click="selectedChapterId = ch.id"
              >
                <span class="title">{{ ch.sort + 1 }}. {{ ch.title }}</span>
                <span class="badge">{{ ch.lessons.length }}</span>
                <el-dropdown
                  trigger="click"
                  @command="(c: string) => onChapterCommand(c, ch)"
                >
                  <el-button
                    link
                    size="small"
                  >
                    ···
                  </el-button>
                  <template #dropdown>
                    <el-dropdown-menu>
                      <el-dropdown-item command="edit">
                        编辑
                      </el-dropdown-item>
                      <el-dropdown-item
                        command="delete"
                        :disabled="ch.lessons.length > 0"
                      >
                        删除
                      </el-dropdown-item>
                    </el-dropdown-menu>
                  </template>
                </el-dropdown>
              </li>
            </ul>
          </aside>
          <section class="lessons">
            <div class="lesson-bar">
              <h3>{{ selectedChapter ? `课节 · ${selectedChapter.title}` : '课节' }}</h3>
              <el-button
                size="small"
                type="primary"
                :disabled="!selectedChapter"
                @click="openLessonDialog(null)"
              >
                新增
              </el-button>
            </div>
            <el-table
              :data="selectedChapter?.lessons ?? []"
              row-key="id"
            >
              <el-table-column
                prop="sort"
                label="#"
                width="60"
              />
              <el-table-column
                prop="title"
                label="标题"
                min-width="200"
              />
              <el-table-column
                label="类型"
                width="100"
              >
                <template #default="{ row }">
                  <el-tag
                    size="small"
                    effect="plain"
                  >
                    {{ contentTypeLabel(row.content_type) }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column
                prop="status"
                label="状态"
                width="80"
              >
                <template #default="{ row }">
                  <el-tag
                    size="small"
                    :type="row.status === 'enabled' ? 'success' : 'info'"
                  >
                    {{ row.status === 'enabled' ? '启用' : '禁用' }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column
                label="操作"
                width="200"
              >
                <template #default="{ row }">
                  <el-button
                    link
                    size="small"
                    type="primary"
                    @click="openLessonDialog(row)"
                  >
                    编辑
                  </el-button>
                  <el-button
                    link
                    size="small"
                    type="danger"
                    @click="onDeleteLesson(row)"
                  >
                    删除
                  </el-button>
                </template>
              </el-table-column>
            </el-table>
          </section>
        </div>
      </el-tab-pane>

      <el-tab-pane
        label="预览"
        name="preview"
      >
        <div
          v-if="tree.id"
          class="preview"
        >
          <h2>{{ tree.title }}</h2>
          <p class="muted">
            讲师：{{ tree.teacher_name }} · {{ formatPrice(tree) }} · <el-tag size="small">
              {{ statusLabel(tree.status) }}
            </el-tag>
          </p>
          <!-- eslint-disable-next-line vue/no-v-html -- ponytail: server-side HtmlSanitizer whitelisted before persist -->
          <div
            v-if="tree.intro_rich_text"
            class="rich"
            v-html="tree.intro_rich_text"
          />
          <el-collapse>
            <el-collapse-item
              v-for="ch in tree.chapters ?? []"
              :key="ch.id"
              :name="ch.id"
            >
              <template #title>
                <strong>{{ ch.sort + 1 }}. {{ ch.title }}</strong>
                <span class="muted small">（{{ ch.lessons.length }} 节）</span>
              </template>
              <ul>
                <li
                  v-for="ls in ch.lessons"
                  :key="ls.id"
                >
                  {{ ls.sort + 1 }}. {{ ls.title }} · {{ contentTypeLabel(ls.content_type) }}
                  <el-tag
                    v-if="ls.is_preview"
                    size="small"
                    type="warning"
                  >
                    试看
                  </el-tag>
                </li>
              </ul>
            </el-collapse-item>
          </el-collapse>
        </div>
        <el-empty
          v-else
          description="先保存草稿后可预览"
        />
      </el-tab-pane>
    </el-tabs>

    <!-- Chapter dialog -->
    <el-dialog
      v-model="chapterDialog.visible"
      :title="chapterDialog.id === null ? '新增章节' : '编辑章节'"
      width="420px"
    >
      <el-form
        label-position="top"
        :model="chapterDialog"
      >
        <el-form-item
          label="标题"
          required
        >
          <el-input
            v-model="chapterDialog.title"
            maxlength="128"
          />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number
            v-model="chapterDialog.sort"
            :min="0"
            :max="999"
          />
        </el-form-item>
        <el-form-item
          v-if="chapterDialog.id !== null"
          label="状态"
        >
          <el-radio-group v-model="chapterDialog.status">
            <el-radio-button value="enabled">
              启用
            </el-radio-button>
            <el-radio-button value="disabled">
              禁用
            </el-radio-button>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="chapterDialog.visible = false">
          取消
        </el-button>
        <el-button
          type="primary"
          :loading="chapterDialog.saving"
          @click="saveChapter"
        >
          保存
        </el-button>
      </template>
    </el-dialog>

    <!-- Lesson dialog -->
    <el-dialog
      v-model="lessonDialog.visible"
      :title="lessonDialog.id === null ? '新增课节' : '编辑课节'"
      width="520px"
      :close-on-click-modal="false"
    >
      <el-form
        label-position="top"
        :model="lessonDialog"
      >
        <el-form-item
          label="标题"
          required
        >
          <el-input
            v-model="lessonDialog.title"
            maxlength="128"
          />
        </el-form-item>
        <el-form-item label="排序">
          <el-input-number
            v-model="lessonDialog.sort"
            :min="0"
            :max="999"
          />
        </el-form-item>
        <el-form-item
          label="内容类型"
          required
        >
          <el-radio-group v-model="lessonDialog.content_type">
            <el-radio-button value="markdown">
              Markdown
            </el-radio-button>
            <el-radio-button value="pdf">
              PDF 资源
            </el-radio-button>
            <el-radio-button value="video">
              视频资源
            </el-radio-button>
          </el-radio-group>
        </el-form-item>
        <el-form-item
          v-if="lessonDialog.content_type === 'markdown'"
          label="正文 (Markdown)"
        >
          <el-input
            v-model="lessonDialog.body_markdown"
            type="textarea"
            :rows="6"
          />
        </el-form-item>
        <el-form-item
          v-else
          label="上传资源"
        >
          <div class="upload-row">
            <el-upload
              v-if="!lessonDialog.asset_id"
              :auto-upload="true"
              :show-file-list="false"
              :http-request="onLessonUpload"
              :accept="lessonDialog.content_type === 'pdf' ? 'application/pdf' : 'video/mp4,video/quicktime'"
            >
              <el-button>选择文件</el-button>
            </el-upload>
            <span
              v-else
              class="asset-id"
            >asset_id: {{ lessonDialog.asset_id }}</span>
            <el-button
              v-if="lessonDialog.asset_id"
              link
              @click="lessonDialog.asset_id = null"
            >
              重新选择
            </el-button>
          </div>
        </el-form-item>
        <el-form-item label="时长 (秒)">
          <el-input-number
            v-model="lessonDialog.duration_seconds"
            :min="0"
            :max="360000"
          />
        </el-form-item>
        <el-form-item label="试看">
          <el-switch v-model="lessonDialog.is_preview" />
        </el-form-item>
        <el-form-item
          v-if="lessonDialog.id !== null"
          label="状态"
        >
          <el-radio-group v-model="lessonDialog.status">
            <el-radio-button value="enabled">
              启用
            </el-radio-button>
            <el-radio-button value="disabled">
              禁用
            </el-radio-button>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="lessonDialog.visible = false">
          取消
        </el-button>
        <el-button
          type="primary"
          :loading="lessonDialog.saving"
          @click="saveLesson"
        >
          保存
        </el-button>
      </template>
    </el-dialog>
  </section>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { ElMessage, ElMessageBox, type UploadRequestOptions } from 'element-plus'
import type {
  CourseDTO,
  ChapterDTO,
  LessonDTO,
  CourseTreeDTO,
  LessonContentType,
  LessonStatus,
  CreateChapterInput,
  UpdateChapterInput,
  CreateLessonInput,
  UpdateLessonInput,
  PriceMode,
  CourseStatus,
} from '@learn-site/contracts'
import {
  listCategoryTree,
  createCourse,
  updateCourse,
  getCourseTree,
  createChapter,
  updateChapter,
  deleteChapter,
  createLesson,
  updateLesson,
  deleteLesson,
  publishCourse,
  uploadAsset,
  type CategoryNode,
} from '@/api/catalog'
import CourseCoverUpload from './CourseCoverUpload.vue'
import { listDepartments } from '@/api/org'
import type { DepartmentDTO } from '@learn-site/contracts'

const route = useRoute()
const router = useRouter()
const activeTab = ref('basic')
const status = ref<'idle' | 'error'>('idle')
const errorMessage = ref('')
const saving = ref(false)
const publishing = ref(false)

const isNew = computed(() => route.name === 'course-new')
const courseId = computed(() => (route.params.id ? Number(route.params.id) : 0))

interface ChapterWithLessons extends ChapterDTO {
  lessons: LessonDTO[]
}

const tree = reactive<Partial<CourseTreeDTO> & { chapters: ChapterWithLessons[] }>({
  id: 0,
  title: '',
  chapters: [],
})

const form = reactive({
  // 新建课程尚未选择部门，用 null 触发选择器占位符；后端要求归属启用部门
  department_id: null as number | null,
  // 新建课程尚未选择分类，用 null 触发 tree-select 占位符
  category_id: null as number | null,
  title: '',
  cover_url: '',
  teacher_name: '',
  summary: '',
  intro_rich_text: '',
  price_mode: 'free' as PriceMode,
  list_price: 0,
  sale_price: 0,
  sale_start_at: '',
  sale_end_at: '',
})

const categoryOptions = ref<CategoryNode[]>([])
const departmentOptions = ref<DepartmentDTO[]>([])
const saleRange = ref<[string, string] | null>(null)

async function loadDepartments(): Promise<void> {
  try {
    const { items } = await listDepartments()
    departmentOptions.value = items.filter((d) => d.status === 'enabled')
  } catch {
    departmentOptions.value = []
  }
  if (isNew.value && form.department_id === null) {
    form.department_id = departmentOptions.value[0]?.id ?? null
  }
}

watch(saleRange, (v) => {
  if (Array.isArray(v) && v.length === 2) {
    form.sale_start_at = v[0] ?? ''
    form.sale_end_at = v[1] ?? ''
  } else {
    form.sale_start_at = ''
    form.sale_end_at = ''
  }
})

watch(form, (v) => {
  if (v.price_mode === 'free') {
    form.list_price = 0
    form.sale_price = 0
    form.sale_start_at = ''
    form.sale_end_at = ''
    saleRange.value = null
  }
})

const selectedChapterId = ref<number | null>(null)
const selectedChapter = computed(() =>
  tree.chapters.find((c) => c.id === selectedChapterId.value) ?? null,
)

function contentTypeLabel(t: LessonContentType): string {
  switch (t) {
    case 'markdown':
      return 'Markdown'
    case 'pdf':
      return 'PDF'
    case 'video':
      return '视频'
  }
}

function statusLabel(s: CourseStatus | undefined): string {
  if (s === 'published') return '已发布'
  if (s === 'unpublished') return '已下架'
  return '草稿'
}

function formatPrice(row: { price_mode?: PriceMode; list_price?: number | null; sale_price?: number | null }): string {
  if (row.price_mode === 'free') return '免费'
  const list = Number(row.list_price ?? 0).toFixed(2)
  const sale = Number(row.sale_price ?? 0)
  if (sale > 0 && sale < Number(row.list_price ?? 0)) return `¥${sale.toFixed(2)} / ¥${list}`
  return `¥${list}`
}

function applyCourse(dto: CourseDTO): void {
  form.department_id = dto.department_id
  form.category_id = dto.category_id
  form.title = dto.title
  form.cover_url = dto.cover_url ?? ''
  form.teacher_name = dto.teacher_name
  form.summary = dto.summary
  form.intro_rich_text = dto.intro_rich_text
  form.price_mode = dto.price_mode
  form.list_price = Number(dto.list_price ?? 0)
  form.sale_price = Number(dto.sale_price ?? 0)
  form.sale_start_at = dto.sale_start_at ?? ''
  form.sale_end_at = dto.sale_end_at ?? ''
  saleRange.value =
    dto.sale_start_at && dto.sale_end_at ? [dto.sale_start_at, dto.sale_end_at] : null
  Object.assign(tree, dto)
  tree.chapters = []
}

async function loadCourseTree(id: number): Promise<void> {
  const t = await getCourseTree(id)
  applyCourse(t)
  tree.id = t.id
  tree.chapters = (t.chapters ?? []).map((ch) => ({
    id: ch.id,
    course_id: ch.course_id,
    title: ch.title,
    sort: ch.sort,
    status: ch.status,
    lessons: ch.lessons ?? [],
  }))
  selectedChapterId.value = tree.chapters[0]?.id ?? null
}

async function loadCategories(): Promise<void> {
  try {
    categoryOptions.value = await listCategoryTree()
  } catch {
    categoryOptions.value = []
  }
}

async function reload(): Promise<void> {
  if (isNew.value) return
  status.value = 'idle'
  errorMessage.value = ''
  try {
    await loadCourseTree(courseId.value)
  } catch (err: unknown) {
    status.value = 'error'
    errorMessage.value = readError(err, '加载课程失败')
  }
}

async function saveDraft(): Promise<void> {
  if (!form.title.trim()) {
    ElMessage.warning('请输入标题')
    activeTab.value = 'basic'
    return
  }
  if (!form.department_id) {
    ElMessage.warning(
      departmentOptions.value.length === 0
        ? '请先在组织管理 → 部门管理创建启用部门'
        : '请选择所属部门',
    )
    activeTab.value = 'basic'
    return
  }
  if (!form.category_id) {
    ElMessage.warning('请选择分类')
    activeTab.value = 'basic'
    return
  }
  saving.value = true
  try {
    const payload = {
      department_id: Number(form.department_id),
      category_id: Number(form.category_id),
      title: form.title.trim(),
      cover_url: form.cover_url.trim() || undefined,
      teacher_name: form.teacher_name.trim(),
      summary: form.summary,
      intro_rich_text: form.intro_rich_text,
      price_mode: form.price_mode,
      list_price: Number(form.list_price),
      sale_price: Number(form.sale_price),
      sale_start_at: form.sale_start_at || undefined,
      sale_end_at: form.sale_end_at || undefined,
    }
    if (isNew.value) {
      const dto = await createCourse(payload)
      router.replace({ name: 'course-edit', params: { id: dto.id } })
      ElMessage.success('已创建草稿')
    } else {
      await updateCourse(courseId.value, payload)
      ElMessage.success('已保存')
    }
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '保存失败'))
  } finally {
    saving.value = false
  }
}

async function onPublish(): Promise<void> {
  if (isNew.value) {
    ElMessage.warning('请先保存为草稿')
    return
  }
  try {
    await ElMessageBox.confirm('确定发布该课程吗？', '发布', { type: 'info' })
  } catch {
    return
  }
  publishing.value = true
  try {
    await publishCourse(courseId.value)
    ElMessage.success('已发布')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '发布失败'))
  } finally {
    publishing.value = false
  }
}

function goPreview(): void {
  if (!isNew.value) router.push(`/courses/${courseId.value}/preview`)
}

function goBack(): void {
  router.push('/courses')
}

// ─── Chapter CRUD ────────────────────────────────────────────────────

const chapterDialog = reactive<{
  visible: boolean
  saving: boolean
  id: number | null
  title: string
  sort: number
  status: LessonStatus
}>({
  visible: false,
  saving: false,
  id: null,
  title: '',
  sort: 0,
  status: 'enabled',
})

function openChapterDialog(row: ChapterDTO | null): void {
  chapterDialog.visible = true
  if (row) {
    chapterDialog.id = row.id
    chapterDialog.title = row.title
    chapterDialog.sort = row.sort
    chapterDialog.status = row.status
  } else {
    chapterDialog.id = null
    chapterDialog.title = ''
    chapterDialog.sort = tree.chapters.length
    chapterDialog.status = 'enabled'
  }
}

async function saveChapter(): Promise<void> {
  if (!chapterDialog.title.trim()) {
    ElMessage.warning('请输入章节标题')
    return
  }
  chapterDialog.saving = true
  try {
    if (chapterDialog.id === null) {
      const input: CreateChapterInput = { title: chapterDialog.title.trim(), sort: chapterDialog.sort }
      await createChapter(courseId.value, input)
    } else {
      const input: UpdateChapterInput = {
        title: chapterDialog.title.trim(),
        sort: chapterDialog.sort,
        status: chapterDialog.status,
      }
      await updateChapter(courseId.value, chapterDialog.id, input)
    }
    chapterDialog.visible = false
    ElMessage.success('已保存章节')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '章节保存失败'))
  } finally {
    chapterDialog.saving = false
  }
}

async function onChapterCommand(cmd: string, row: ChapterDTO): Promise<void> {
  if (cmd === 'edit') {
    openChapterDialog(row)
    return
  }
  if (cmd === 'delete') {
    try {
      await ElMessageBox.confirm(`删除章节「${row.title}」？`, '确认', { type: 'warning' })
    } catch {
      return
    }
    try {
      await deleteChapter(courseId.value, row.id)
      ElMessage.success('已删除')
      if (selectedChapterId.value === row.id) selectedChapterId.value = null
      await reload()
    } catch (err: unknown) {
      ElMessage.error(readError(err, '删除失败'))
    }
  }
}

// ─── Lesson CRUD ─────────────────────────────────────────────────────

const lessonDialog = reactive<{
  visible: boolean
  saving: boolean
  id: number | null
  chapter_id: number | null
  title: string
  sort: number
  content_type: LessonContentType
  body_markdown: string
  asset_id: number | null
  is_preview: boolean
  duration_seconds: number
  status: LessonStatus
}>({
  visible: false,
  saving: false,
  id: null,
  chapter_id: null,
  title: '',
  sort: 0,
  content_type: 'markdown',
  body_markdown: '',
  asset_id: null,
  is_preview: false,
  duration_seconds: 0,
  status: 'enabled',
})

function openLessonDialog(row: LessonDTO | null): void {
  lessonDialog.visible = true
  if (row) {
    lessonDialog.id = row.id
    lessonDialog.chapter_id = row.chapter_id
    lessonDialog.title = row.title
    lessonDialog.sort = row.sort
    lessonDialog.content_type = row.content_type
    lessonDialog.body_markdown = row.body_markdown ?? ''
    lessonDialog.asset_id = row.asset_id ?? null
    lessonDialog.is_preview = row.is_preview
    lessonDialog.duration_seconds = row.duration_seconds
    lessonDialog.status = row.status
  } else {
    const ch = selectedChapter.value
    lessonDialog.id = null
    lessonDialog.chapter_id = ch?.id ?? null
    lessonDialog.title = ''
    lessonDialog.sort = ch ? ch.lessons.length : 0
    lessonDialog.content_type = 'markdown'
    lessonDialog.body_markdown = ''
    lessonDialog.asset_id = null
    lessonDialog.is_preview = false
    lessonDialog.duration_seconds = 0
    lessonDialog.status = 'enabled'
  }
}

async function onLessonUpload(req: UploadRequestOptions): Promise<void> {
  const file = req.file as File
  const kind = lessonDialog.content_type === 'pdf' ? 'pdf' : 'video'
  try {
    const res = await uploadAsset({ file, kind })
    lessonDialog.asset_id = res.id
    ElMessage.success('上传成功')
  } catch (err: unknown) {
    ElMessage.error(readError(err, '上传失败'))
  }
}

async function saveLesson(): Promise<void> {
  if (!selectedChapter.value) {
    ElMessage.warning('请先选择章节')
    return
  }
  if (!lessonDialog.title.trim()) {
    ElMessage.warning('请输入课节标题')
    return
  }
  if (lessonDialog.content_type === 'markdown' && !lessonDialog.body_markdown.trim()) {
    ElMessage.warning('Markdown 正文不能为空')
    return
  }
  if (lessonDialog.content_type !== 'markdown' && !lessonDialog.asset_id) {
    ElMessage.warning('请上传资源')
    return
  }
  lessonDialog.saving = true
  try {
    if (lessonDialog.id === null) {
      const input: CreateLessonInput = {
        chapter_id: selectedChapter.value.id,
        title: lessonDialog.title.trim(),
        sort: lessonDialog.sort,
        content_type: lessonDialog.content_type,
        body_markdown:
          lessonDialog.content_type === 'markdown' ? lessonDialog.body_markdown : undefined,
        asset_id:
          lessonDialog.content_type !== 'markdown' && lessonDialog.asset_id
            ? lessonDialog.asset_id
            : undefined,
        is_preview: lessonDialog.is_preview,
        duration_seconds: lessonDialog.duration_seconds,
      }
      await createLesson(courseId.value, input)
    } else {
      const input: UpdateLessonInput = {
        title: lessonDialog.title.trim(),
        sort: lessonDialog.sort,
        status: lessonDialog.status,
        content_type: lessonDialog.content_type,
        body_markdown: lessonDialog.content_type === 'markdown' ? lessonDialog.body_markdown : undefined,
        asset_id: lessonDialog.content_type !== 'markdown' && lessonDialog.asset_id !== null ? lessonDialog.asset_id : undefined,
        is_preview: lessonDialog.is_preview,
        duration_seconds: lessonDialog.duration_seconds,
      }
      await updateLesson(courseId.value, lessonDialog.id, input)
    }
    lessonDialog.visible = false
    ElMessage.success('已保存课节')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '课节保存失败'))
  } finally {
    lessonDialog.saving = false
  }
}

async function onDeleteLesson(row: LessonDTO): Promise<void> {
  try {
    await ElMessageBox.confirm(`删除课节「${row.title}」？`, '确认', { type: 'warning' })
  } catch {
    return
  }
  try {
    await deleteLesson(courseId.value, row.id)
    ElMessage.success('已删除')
    await reload()
  } catch (err: unknown) {
    ElMessage.error(readError(err, '删除失败'))
  }
}

function readError(err: unknown, fallback: string): string {
  const code = (err as { response?: { data?: { error?: { code?: string; message?: string } } } })
    ?.response?.data?.error?.code
  const message = (err as { response?: { data?: { error?: { message?: string } } } })
    ?.response?.data?.error?.message
  if (code === 'VALIDATION_FAILED') return message ?? '校验失败'
  if (code === 'NOT_FOUND') return '资源不存在'
  if (code === 'CONFLICT') return message ?? '操作冲突'
  if (code === 'CATEGORY_IN_USE') return '分类仍在使用中'
  return fallback
}

onMounted(async () => {
  await Promise.all([loadCategories(), loadDepartments()])
  if (!isNew.value) await reload()
})
</script>

<style scoped>
.page {
  background: #ffffff;
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
}
.bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  margin-bottom: 12px;
}
.bar h2 {
  margin: 4px 0 0;
  font-size: 18px;
  color: #0f172a;
}
.title {
  margin-top: 4px;
}
.actions {
  display: flex;
  gap: 8px;
}
.tabs {
  margin-top: 8px;
}
.form-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px 20px;
}
.form-grid :deep(.el-form-item) {
  margin-bottom: 16px;
}
.span-2 {
  grid-column: span 2;
}
.field-hint {
  margin: -4px 0 12px;
  font-size: 12px;
  color: #b45309;
}
.structure {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 16px;
}
.chapters,
.lessons {
  background: #f8fafc;
  border-radius: 8px;
  padding: 12px;
  border: 1px solid #e2e8f0;
}
.chapter-bar,
.lesson-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}
.chapters h3,
.lessons h3 {
  margin: 0;
  font-size: 14px;
  color: #0f172a;
}
.chapters ul {
  list-style: none;
  margin: 0;
  padding: 0;
}
.chapters li {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 10px;
  border-radius: 6px;
  cursor: pointer;
  font-size: 13px;
  color: #0f172a;
}
.chapters li.active {
  background: #e0f2fe;
}
.chapters li .title {
  flex: 1 1 auto;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.chapters li .badge {
  background: #0ea5e9;
  color: white;
  border-radius: 999px;
  font-size: 11px;
  padding: 0 6px;
  line-height: 16px;
  min-width: 16px;
  text-align: center;
}
.upload-row {
  display: flex;
  gap: 8px;
  align-items: center;
}
.asset-id {
  font-family: monospace;
  font-size: 12px;
  color: #475569;
}
.preview .rich {
  background: #f8fafc;
  padding: 16px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}
.preview .muted {
  color: #64748b;
}
.preview .small {
  font-size: 12px;
}
</style>
