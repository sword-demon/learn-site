<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { hasPermission } from '@/api/http';
import {
  addCourseToStage,
  addStage,
  createMap,
  deleteMap,
  deleteStage,
  getMap,
  listMaps,
  publishMap,
  removeCourseFromStage,
  unpublishMap,
  updateMap,
  updateStage,
  uploadMapCover,
} from '@/api/learningMaps';
import { listDepartments } from '@/api/org';
import type {
  AdminMapDetailDTO,
  AdminMapListDTO,
  DepartmentDTO,
  MapPublishIssueDTO,
  MapStatus,
} from '@learn-site/contracts';
import { listCourses } from '@/api/catalog';
import type { CourseDTO } from '@learn-site/contracts';
import CourseCoverUpload from '@/views/catalog/CourseCoverUpload.vue';
import {
  CircleCheck,
  Collection,
  Delete,
  MapLocation,
  Plus,
  Promotion,
  Remove,
  Setting,
} from '@element-plus/icons-vue';

defineOptions({ name: 'MapEditorView' });

const route = useRoute();
const router = useRouter();
const canManage = hasPermission('map.manage');
const canPublish = hasPermission('map.publish');

const list = ref<AdminMapListDTO | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const departments = ref<DepartmentDTO[]>([]);
const courses = ref<CourseDTO[]>([]);

const active = ref<AdminMapDetailDTO | null>(null);
const activeError = ref<string | null>(null);
const submitting = ref(false);

const newMap = ref({ department_id: 0, title: '', summary: '' });
const mapSettings = ref({
  department_id: 0,
  title: '',
  summary: '',
  cover_url: '',
  objective: '',
  audience: '',
});
const newStage = ref({ title: '', summary: '' });
const addCourseStageId = ref<number | null>(null);
const addCourseId = ref<number>(0);

type MapStatusFilter = 'all' | MapStatus;

const statusOptions: Array<{ value: MapStatusFilter; label: string }> = [
  { value: 'all', label: '全部' },
  { value: 'draft', label: '草稿' },
  { value: 'published', label: '已发布' },
];
const filterStatus = ref<MapStatusFilter>('all');
const availableCourses = computed(() => {
  const usedCourseIds = new Set(
    active.value?.stages?.flatMap((stage) => stage.courses?.map((step) => step.course_id) ?? []) ??
      [],
  );
  return courses.value.filter((course) => !usedCourseIds.has(course.id));
});
const mapItems = computed(() => list.value?.items ?? []);

async function loadList(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    const result = await listMaps({
      ...(filterStatus.value === 'all' ? {} : { status: filterStatus.value }),
      page: 1,
      limit: 50,
    });
    list.value = { ...result, items: result.items ?? [] };
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
  } finally {
    loading.value = false;
  }
}

async function loadActive(id: number): Promise<void> {
  activeError.value = null;
  try {
    setActive(await getMap(id));
  } catch (err) {
    activeError.value = (err as Error).message || 'load_failed';
  }
}

function setActive(detail: AdminMapDetailDTO): void {
  active.value = {
    ...detail,
    stages: detail.stages ?? [],
    publish_issues: detail.publish_issues ?? [],
  };
  mapSettings.value = {
    department_id: detail.department_id,
    title: detail.title,
    summary: detail.summary ?? '',
    cover_url: detail.cover_url ?? '',
    objective: detail.objective ?? '',
    audience: detail.audience ?? '',
  };
}

async function loadLookups(): Promise<void> {
  if (!canManage) return;
  try {
    const d = await listDepartments();
    departments.value = d.items.filter((x) => x.status === 'enabled');
    const firstDepartment = departments.value[0];
    if (firstDepartment && newMap.value.department_id === 0) {
      newMap.value.department_id = firstDepartment.id;
    }
  } catch {
    departments.value = [];
  }
  try {
    const res = await listCourses({ page: 1, limit: 100 });
    courses.value = res.items;
  } catch {
    courses.value = [];
  }
}

async function submitCreate(): Promise<void> {
  if (!canManage || submitting.value) return;
  if (newMap.value.department_id <= 0 || !newMap.value.title.trim()) return;
  submitting.value = true;
  try {
    const detail = await createMap({
      department_id: newMap.value.department_id,
      title: newMap.value.title.trim(),
      summary: newMap.value.summary.trim() || null,
    });
    newMap.value = { department_id: newMap.value.department_id, title: '', summary: '' };
    await loadList();
    await loadActive(detail.id);
    router.replace({ query: { id: String(detail.id) } });
  } catch (err) {
    activeError.value = (err as Error).message || 'CREATE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function publishToggle(): Promise<void> {
  if (!canPublish || !active.value || submitting.value) return;
  if (active.value.status === 'draft' && (active.value.publish_issues?.length ?? 0) > 0) return;
  submitting.value = true;
  try {
    setActive(
      active.value.status === 'published'
        ? await unpublishMap(active.value.id)
        : await publishMap(active.value.id),
    );
    await loadList();
  } catch (err) {
    activeError.value = (err as Error).message || 'STATUS_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitMapSettings(): Promise<void> {
  if (!canManage || !active.value || submitting.value || !mapSettings.value.title.trim()) return;
  submitting.value = true;
  activeError.value = null;
  try {
    setActive(
      await updateMap(active.value.id, {
        department_id: mapSettings.value.department_id,
        title: mapSettings.value.title.trim(),
        summary: mapSettings.value.summary.trim() || null,
        cover_url: mapSettings.value.cover_url.trim() || null,
        objective: mapSettings.value.objective.trim() || null,
        audience: mapSettings.value.audience.trim() || null,
      }),
    );
    await loadList();
  } catch (err) {
    activeError.value = (err as Error).message || 'UPDATE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function destroyActive(): Promise<void> {
  if (!canManage || !active.value || submitting.value) return;
  if (!confirm(`确认删除地图 #${active.value.id}?`)) return;
  submitting.value = true;
  try {
    await deleteMap(active.value.id);
    active.value = null;
    router.replace({ query: {} });
    await loadList();
  } catch (err) {
    activeError.value = (err as Error).message || 'DELETE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function submitStage(): Promise<void> {
  if (!canManage || !active.value || submitting.value) return;
  if (!newStage.value.title.trim()) return;
  submitting.value = true;
  try {
    await addStage(active.value.id, {
      title: newStage.value.title.trim(),
      summary: newStage.value.summary.trim() || null,
    });
    newStage.value = { title: '', summary: '' };
    await loadActive(active.value.id);
  } catch (err) {
    activeError.value = (err as Error).message || 'STAGE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function removeStage(stageId: number): Promise<void> {
  if (!canManage || !active.value || submitting.value) return;
  if (!confirm('确认删除该阶段及其课程?')) return;
  submitting.value = true;
  try {
    await deleteStage(active.value.id, stageId);
    await loadActive(active.value.id);
  } catch (err) {
    activeError.value = (err as Error).message || 'STAGE_DELETE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function updateStageField(
  stageId: number,
  input: { title?: string; summary?: string | null; sort_order?: number },
): Promise<void> {
  if (!canManage || !active.value) return;
  submitting.value = true;
  try {
    await updateStage(active.value.id, stageId, input);
    await loadActive(active.value.id);
  } catch (err) {
    activeError.value = (err as Error).message || 'STAGE_UPDATE_FAILED';
  } finally {
    submitting.value = false;
  }
}

function updateStageSummaryValue(stageId: number, value: string): void {
  void updateStageField(stageId, { summary: value.trim() || null });
}

async function submitAddCourse(): Promise<void> {
  if (!canManage || !active.value || addCourseStageId.value === null || addCourseId.value <= 0)
    return;
  submitting.value = true;
  try {
    await addCourseToStage(active.value.id, addCourseStageId.value, {
      course_id: addCourseId.value,
    });
    addCourseStageId.value = null;
    addCourseId.value = 0;
    await loadActive(active.value.id);
  } catch (err) {
    activeError.value = (err as Error).message || 'ADD_COURSE_FAILED';
  } finally {
    submitting.value = false;
  }
}

async function dropCourse(stageId: number, courseId: number): Promise<void> {
  if (!canManage || !active.value) return;
  submitting.value = true;
  try {
    await removeCourseFromStage(active.value.id, stageId, courseId);
    await loadActive(active.value.id);
  } catch (err) {
    activeError.value = (err as Error).message || 'DROP_COURSE_FAILED';
  } finally {
    submitting.value = false;
  }
}

const queryId = computed(() => Number(route.query.id));
watch(
  queryId,
  async (v) => {
    if (Number.isFinite(v) && v > 0) {
      await loadActive(v);
    } else {
      active.value = null;
    }
  },
  { immediate: true },
);

watch(filterStatus, loadList);

onMounted(async () => {
  await loadLookups();
  await loadList();
});

function statusLabel(s: MapStatus): string {
  return s === 'published' ? '已发布' : '草稿';
}

function statusType(s: MapStatus): 'success' | 'info' {
  return s === 'published' ? 'success' : 'info';
}

function courseStatusLabel(status: string): string {
  if (status === 'published') return '已发布';
  if (status === 'unpublished') return '已下架';
  return '草稿';
}

function publishIssueLabel(issue: MapPublishIssueDTO): string {
  if (!active.value) return issue.code;
  if (issue.code === 'MAP_HAS_NO_STAGES') {
    return '地图至少需要一个阶段';
  }
  const stage = active.value.stages.find((item) => item.id === issue.stage_id);
  if (issue.code === 'STAGE_HAS_NO_COURSES') {
    return stage ? `阶段「${stage.title}」还没有课程` : `阶段 #${issue.stage_id ?? '-'} 还没有课程`;
  }
  const step = active.value.stages
    .flatMap((item) => item.courses)
    .find((item) => item.course_id === issue.course_id);
  return step?.course
    ? `课程「${step.course.title}」尚未发布`
    : `课程 #${issue.course_id ?? '-'} 尚未发布或已不可用`;
}
</script>

<template>
  <section class="page map-editor">
    <header class="page-head">
      <div class="title-block">
        <span class="section-kicker">内容编排 / 学习路径</span>
        <h1 class="display">学习地图</h1>
        <p class="subtitle">把课程组织成可执行的成长路径，并在发布前检查内容完整性。</p>
      </div>
      <div class="head-metric">
        <span>地图总数</span>
        <strong>{{ list?.total ?? 0 }}</strong>
        <span>条配置</span>
      </div>
    </header>

    <el-card class="filter-panel" shadow="never">
      <el-form inline class="filter-form">
        <el-form-item label="发布状态">
          <el-select
            v-model="filterStatus"
            class="filter-control"
            placement="bottom-start"
            :teleported="true"
            clearable
            placeholder="全部"
            data-field="status"
          >
            <el-option
              v-for="opt in statusOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
      </el-form>
    </el-card>

    <el-alert
      v-if="loadError"
      title="学习地图暂时读不到"
      :description="loadError"
      type="error"
      show-icon
      :closable="false"
    />

    <div class="layout">
      <el-card class="left-pane" shadow="never">
        <template #header>
          <div class="panel-heading">
            <div>
              <h2>地图目录</h2>
              <p>选择一条地图进入编辑</p>
            </div>
            <el-tag type="info" effect="plain">{{ list?.items?.length ?? 0 }} 条</el-tag>
          </div>
        </template>
        <el-form
          v-if="canManage"
          data-role="new-map"
          class="new-map"
          @submit.prevent="submitCreate"
        >
          <div class="section-title"><Plus /><span>新建地图</span></div>
          <div class="compact-form">
            <el-form-item label="所属部门" required>
              <el-select
                v-model="newMap.department_id"
                clearable
                class="full-control"
                :teleported="false"
              >
                <el-option v-for="d in departments" :key="d.id" :label="d.name" :value="d.id" />
              </el-select>
            </el-form-item>
            <el-form-item label="标题" required>
              <el-input
                v-model="newMap.title"
                clearable
                maxlength="128"
                placeholder="例如：前端工程师成长路线"
              />
            </el-form-item>
            <el-form-item label="简介">
              <el-input
                v-model="newMap.summary"
                clearable
                maxlength="255"
                placeholder="一句话说明学习路径"
              />
            </el-form-item>
          </div>
          <el-button type="primary" native-type="submit" :loading="submitting" class="full-control">
            <el-icon><Plus /></el-icon>
            创建草稿
          </el-button>
        </el-form>

        <el-divider v-if="canManage" />
        <el-skeleton v-if="loading" :rows="5" animated />
        <el-empty v-else-if="mapItems.length === 0" description="暂无学习地图" :image-size="88" />
        <ol v-else class="map-list">
          <li
            v-for="m in mapItems"
            :key="m.id"
            class="map-item"
            :class="{ active: active && active.id === m.id }"
          >
            <el-button
              text
              class="map-button"
              @click="router.replace({ query: { id: String(m.id) } })"
            >
              <span class="map-marker"><MapLocation /></span>
              <span class="map-copy">
                <strong>{{ m.title }}</strong>
                <small>更新于 {{ m.updated_at }}</small>
              </span>
              <el-tag :type="statusType(m.status)" effect="light" size="small">{{
                statusLabel(m.status)
              }}</el-tag>
            </el-button>
          </li>
        </ol>
      </el-card>

      <el-card class="right-pane" shadow="never">
        <el-alert
          v-if="activeError"
          title="操作未完成"
          :description="activeError"
          type="error"
          show-icon
          :closable="false"
        />
        <el-empty v-if="!active" description="从左侧选择地图开始编辑" :image-size="110" />
        <template v-else>
          <header class="map-head">
            <div class="map-title-block">
              <span class="section-kicker">地图编辑</span>
              <h2>{{ active.title }}</h2>
              <p v-if="active.summary" class="summary">{{ active.summary }}</p>
              <div class="map-meta-list">
                <span v-if="active.objective">目标：{{ active.objective }}</span>
                <span v-if="active.audience">适用人群：{{ active.audience }}</span>
              </div>
            </div>
            <div class="actions">
              <el-tag :type="statusType(active.status)" effect="light">{{
                statusLabel(active.status)
              }}</el-tag>
              <el-button
                v-if="canPublish"
                type="primary"
                plain
                data-action="publish"
                :disabled="
                  submitting || (active.status === 'draft' && active.publish_issues?.length > 0)
                "
                @click="publishToggle"
              >
                <el-icon><Promotion /></el-icon>
                {{ active.status === 'published' ? '下架' : '发布' }}
              </el-button>
              <el-button
                v-if="canManage && active.status === 'draft'"
                type="danger"
                plain
                :disabled="submitting"
                @click="destroyActive"
              >
                <el-icon><Delete /></el-icon>
                删除草稿
              </el-button>
            </div>
          </header>

          <el-alert
            v-if="active.publish_issues?.length"
            data-role="publish-issues"
            class="publish-issues"
            title="发布前需要处理"
            type="warning"
            show-icon
            :closable="false"
          >
            <ul class="issue-list">
              <li
                v-for="issue in active.publish_issues"
                :key="`${issue.code}-${issue.stage_id}-${issue.course_id}`"
              >
                {{ publishIssueLabel(issue) }}
              </li>
            </ul>
          </el-alert>

          <el-form
            v-if="canManage"
            data-role="map-settings"
            class="map-settings"
            label-position="top"
            @submit.prevent="submitMapSettings"
          >
            <div class="settings-heading">
              <div>
                <div class="section-title settings-title"><Setting /><span>地图设置</span></div>
                <p class="settings-intro">
                  完善地图的定位与学习画像，让学员一眼知道这条路径适合谁。
                </p>
              </div>
              <span class="settings-caption">基础信息</span>
            </div>
            <div class="settings-layout">
              <div class="settings-fields">
                <div class="settings-grid">
                  <el-form-item label="标题" required>
                    <el-input
                      v-model="mapSettings.title"
                      clearable
                      name="title"
                      maxlength="128"
                      placeholder="例如：前端工程师成长路线"
                    />
                  </el-form-item>
                  <el-form-item label="所属部门" required>
                    <el-select
                      v-model="mapSettings.department_id"
                      clearable
                      name="department_id"
                      data-field="department_id"
                      :teleported="false"
                    >
                      <el-option
                        v-for="d in departments"
                        :key="d.id"
                        :label="d.name"
                        :value="d.id"
                      />
                    </el-select>
                  </el-form-item>
                  <el-form-item label="简介" class="settings-wide">
                    <el-input
                      v-model="mapSettings.summary"
                      clearable
                      name="summary"
                      maxlength="255"
                      placeholder="用一句话说明这条学习路径将带来的成长"
                    />
                  </el-form-item>
                  <el-form-item label="学习目标">
                    <el-input
                      v-model="mapSettings.objective"
                      clearable
                      name="objective"
                      type="textarea"
                      :rows="4"
                      placeholder="学完这条地图后，学员能够独立完成什么？"
                    />
                  </el-form-item>
                  <el-form-item label="适用人群">
                    <el-input
                      v-model="mapSettings.audience"
                      clearable
                      name="audience"
                      type="textarea"
                      :rows="4"
                      placeholder="例如：有 1 年前端经验，希望提升工程能力的开发者"
                    />
                  </el-form-item>
                </div>
              </div>
              <aside class="cover-workbench" aria-labelledby="map-cover-title">
                <div class="cover-workbench-head">
                  <div>
                    <span class="field-kicker">视觉入口</span>
                    <h3 id="map-cover-title">地图封面</h3>
                  </div>
                  <span class="optional-mark">可选</span>
                </div>
                <CourseCoverUpload
                  v-model="mapSettings.cover_url"
                  data-role="map-cover-upload"
                  :upload="uploadMapCover"
                />
                <p class="cover-description">
                  选择一张能代表学习主题的横版图片，建议使用 16:9 构图。
                </p>
              </aside>
            </div>
            <div class="settings-footer">
              <span class="save-note"><CircleCheck /> 修改信息后记得保存</span>
              <el-button
                type="primary"
                native-type="submit"
                :disabled="submitting || !mapSettings.title.trim()"
              >
                <el-icon><CircleCheck /></el-icon>
                保存设置
              </el-button>
            </div>
          </el-form>

          <el-form
            v-if="canManage"
            data-role="new-stage"
            class="new-stage"
            label-position="top"
            @submit.prevent="submitStage"
          >
            <div class="stage-form-heading">
              <div class="section-title"><Plus /><span>添加阶段</span></div>
              <p>先搭建学习顺序，再把课程放进对应阶段。</p>
            </div>
            <div class="new-stage-form">
              <el-form-item label="阶段标题" required class="stage-name-field">
                <el-input
                  v-model="newStage.title"
                  clearable
                  maxlength="128"
                  placeholder="例如：类型基础"
                />
              </el-form-item>
              <el-form-item label="阶段简介" class="stage-summary-field">
                <el-input
                  v-model="newStage.summary"
                  clearable
                  maxlength="255"
                  placeholder="阶段学习重点"
                />
              </el-form-item>
              <el-form-item class="stage-action-field">
                <el-button type="primary" native-type="submit" :loading="submitting">
                  <el-icon><Plus /></el-icon>
                  添加阶段
                </el-button>
              </el-form-item>
            </div>
          </el-form>

          <div class="stage-heading">
            <div>
              <h3>学习阶段</h3>
              <span>按顺序组织课程内容</span>
            </div>
            <el-tag type="info" effect="plain">{{ active.stages?.length ?? 0 }} 个阶段</el-tag>
          </div>
          <ol class="stages">
            <li
              v-for="stage in active.stages"
              :key="stage.id"
              class="stage"
              :data-stage-id="stage.id"
            >
              <header class="stage-head">
                <template v-if="canManage">
                  <el-input
                    class="stage-title"
                    clearable
                    :model-value="stage.title"
                    maxlength="128"
                    @change="updateStageField(stage.id, { title: $event })"
                  />
                  <el-input-number
                    class="stage-order"
                    :model-value="stage.sort_order"
                    :min="1"
                    controls-position="right"
                    @change="updateStageField(stage.id, { sort_order: Number($event) })"
                  />
                  <el-button
                    type="danger"
                    plain
                    data-action="delete-stage"
                    :disabled="submitting"
                    @click="removeStage(stage.id)"
                  >
                    <el-icon><Delete /></el-icon>
                    删除
                  </el-button>
                </template>
                <div v-else class="stage-copy">
                  <h3>{{ stage.title }}</h3>
                  <p v-if="stage.summary">{{ stage.summary }}</p>
                </div>
              </header>
              <el-form v-if="canManage" label-position="top" class="stage-summary">
                <el-form-item label="阶段说明">
                  <el-input
                    name="stage_summary"
                    clearable
                    type="textarea"
                    :rows="2"
                    :model-value="stage.summary ?? ''"
                    @change="updateStageSummaryValue(stage.id, $event)"
                  />
                </el-form-item>
              </el-form>
              <ol v-if="stage.courses?.length" class="course-list">
                <li v-for="sc in stage.courses" :key="sc.map_stage_course_id" class="course">
                  <div class="course-icon"><Collection /></div>
                  <div class="course-copy">
                    <span class="course-title">{{
                      sc.course?.title ?? `课程 #${sc.course_id}`
                    }}</span>
                    <span v-if="sc.course" class="course-meta">
                      {{ sc.course.teacher_name }} · {{ courseStatusLabel(sc.course.status) }}
                    </span>
                  </div>
                  <el-button
                    v-if="canManage"
                    type="danger"
                    link
                    data-action="remove-course"
                    :disabled="submitting"
                    @click="dropCourse(stage.id, sc.course_id)"
                  >
                    <el-icon><Remove /></el-icon>
                    移除
                  </el-button>
                </li>
              </ol>
              <el-empty v-else description="该阶段暂无课程" :image-size="64" />

              <el-form
                v-if="canManage && addCourseStageId === stage.id"
                class="add-course add-course-form"
                @submit.prevent="submitAddCourse"
              >
                <div>
                  <el-form-item label="选择课程" required>
                    <el-select
                      v-model="addCourseId"
                      clearable
                      data-field="course_id"
                      :teleported="false"
                      placeholder="请选择课程"
                    >
                      <el-option
                        v-for="c in availableCourses"
                        :key="c.id"
                        :label="c.title"
                        :value="c.id"
                      />
                    </el-select>
                  </el-form-item>
                </div>
                <div class="row-end">
                  <el-button @click="addCourseStageId = null">取消</el-button>
                  <el-button type="primary" native-type="submit" :disabled="submitting">
                    <el-icon><Plus /></el-icon>
                    添加课程
                  </el-button>
                </div>
              </el-form>
              <el-button
                v-else-if="canManage"
                plain
                data-action="add-course"
                :disabled="submitting || availableCourses.length === 0"
                @click="
                  addCourseStageId = stage.id;
                  addCourseId = 0;
                "
              >
                <el-icon><Plus /></el-icon>
                添加课程
              </el-button>
            </li>
          </ol>
        </template>
      </el-card>
    </div>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 18px;
  min-width: 0;
}
.page-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 18px;
}
.title-block {
  min-width: 0;
}
.section-kicker {
  display: block;
  margin-bottom: 6px;
  color: #168da7;
  font-size: 11px;
  font-weight: 700;
  letter-spacing: 0.12em;
}
.display {
  margin: 0;
  color: #102a43;
  font-size: clamp(1.6rem, 2vw, 2rem);
  letter-spacing: -0.025em;
}
.subtitle {
  max-width: 620px;
  margin: 7px 0 0;
  color: #6b7c93;
  font-size: 13px;
}
.head-metric {
  display: grid;
  min-width: 132px;
  padding-left: 18px;
  border-left: 1px solid #d8e2eb;
  color: #6b7c93;
  font-size: 12px;
  line-height: 1.4;
}
.head-metric strong {
  color: #102a43;
  font-size: 25px;
  line-height: 1.15;
}
.filter-panel,
.left-pane,
.right-pane {
  --el-card-border-color: #dce6ef;
  --el-card-padding: 18px;
  border-radius: 8px;
  box-shadow: 0 8px 24px rgba(16, 42, 67, 0.04);
}
.filter-panel :deep(.el-card__body) {
  padding: 14px 18px;
}
.filter-form {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 0 18px;
}
.filter-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.filter-form :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 13px;
  font-weight: 600;
}
.filter-control {
  width: 190px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(290px, 360px) minmax(0, 1fr);
  align-items: start;
  gap: 18px;
}
.left-pane :deep(.el-card__header),
.right-pane :deep(.el-card__header) {
  padding: 16px 18px;
}
.panel-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
}
.panel-heading h2 {
  margin: 0;
  color: #102a43;
  font-size: 15px;
}
.panel-heading p {
  margin: 3px 0 0;
  color: #829ab1;
  font-size: 12px;
}
.new-map {
  display: grid;
  gap: 8px;
}
.section-title {
  display: flex;
  align-items: center;
  gap: 7px;
  margin-bottom: 12px;
  color: #243b53;
  font-size: 14px;
  font-weight: 700;
}
.section-title svg {
  width: 16px;
  color: #168da7;
}
.compact-form :deep(.el-form-item),
.settings-grid :deep(.el-form-item) {
  margin-bottom: 14px;
}
.compact-form :deep(.el-form-item__label),
.settings-grid :deep(.el-form-item__label),
.new-stage-form :deep(.el-form-item__label),
.add-course-form :deep(.el-form-item__label),
.stage-summary :deep(.el-form-item__label) {
  color: #52667a;
  font-size: 12px;
  font-weight: 600;
}
.full-control {
  width: 100%;
}
.map-list {
  display: grid;
  gap: 6px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.map-item {
  border: 1px solid #e4ebf1;
  border-radius: 7px;
  transition:
    border-color 0.18s ease,
    background-color 0.18s ease;
}
.map-item.active {
  border-color: #55b8c5;
  background: #f1fafb;
}
.map-button {
  display: flex;
  width: 100%;
  height: auto;
  min-height: 66px;
  padding: 10px 11px;
  align-items: center;
  gap: 9px;
  text-align: left;
  white-space: normal;
}
.map-button:hover {
  color: #102a43;
  background: transparent;
}
.map-marker {
  display: grid;
  width: 28px;
  height: 28px;
  flex: 0 0 28px;
  place-items: center;
  border-radius: 7px;
  color: #168da7;
  background: #e7f6f8;
}
.map-copy {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 3px;
}
.map-copy strong {
  overflow-wrap: anywhere;
  color: #243b53;
  font-size: 13px;
}
.map-copy small {
  overflow-wrap: anywhere;
  color: #829ab1;
  font-size: 11px;
}
.map-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  padding-bottom: 16px;
  margin-bottom: 16px;
  border-bottom: 1px solid #e6edf3;
}
.map-title-block {
  min-width: 0;
}
.map-head h2 {
  margin: 0;
  color: #102a43;
  font-size: 21px;
  letter-spacing: -0.02em;
}
.map-meta-list {
  display: flex;
  flex-wrap: wrap;
  gap: 5px 16px;
  margin-top: 9px;
  color: #6b7c93;
  font-size: 12px;
}
.summary {
  max-width: 680px;
  margin: 6px 0 0;
  color: #52667a;
  font-size: 13px;
}
.actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
.publish-issues {
  margin-bottom: 16px;
}
.issue-list {
  padding-left: 18px;
  margin: 4px 0 0;
  line-height: 1.7;
}
.map-settings {
  display: grid;
  gap: 18px;
  padding-bottom: 22px;
  margin-bottom: 18px;
  border-bottom: 1px solid #dfe9ee;
}
.settings-heading {
  display: flex;
  align-items: flex-end;
  justify-content: space-between;
  gap: 18px;
  padding-bottom: 2px;
}
.settings-title {
  margin-bottom: 5px;
}
.settings-intro {
  margin: 0;
  color: #6b7c93;
  font-size: 12px;
  line-height: 1.6;
}
.settings-caption {
  flex: 0 0 auto;
  padding: 5px 9px;
  border: 1px solid #d6e5e6;
  border-radius: 999px;
  color: #277c7d;
  background: #f2f9f8;
  font-size: 11px;
  font-weight: 700;
}
.settings-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 230px;
  gap: 20px;
  align-items: start;
}
.settings-fields {
  min-width: 0;
}
.settings-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 16px;
}
.settings-grid .settings-wide {
  grid-column: 1 / -1;
}
.settings-grid :deep(.el-form-item__label),
.map-settings :deep(.el-form-item__label) {
  padding-bottom: 6px;
  color: #52667a;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.2;
}
.settings-grid :deep(.el-select),
.settings-grid :deep(.el-input) {
  width: 100%;
}
.settings-grid :deep(.el-input__wrapper),
.settings-grid :deep(.el-textarea__inner) {
  border-radius: 7px;
}
.settings-grid :deep(.el-textarea__inner) {
  min-height: 104px;
  resize: vertical;
}
.cover-workbench {
  min-width: 0;
  padding: 15px;
  border: 1px solid #cfe5e2;
  border-left: 3px solid #2ca6a4;
  border-radius: 9px;
  background: #f5fbfa;
}
.cover-workbench-head {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 10px;
  margin-bottom: 13px;
}
.field-kicker {
  display: block;
  margin-bottom: 4px;
  color: #3c9692;
  font-size: 10px;
  font-weight: 800;
  letter-spacing: 0.12em;
  text-transform: uppercase;
}
.cover-workbench h3 {
  margin: 0;
  color: #173f4a;
  font-size: 15px;
}
.optional-mark {
  padding: 3px 6px;
  border-radius: 4px;
  color: #a15c16;
  background: #fff2df;
  font-size: 10px;
  font-weight: 700;
}
.cover-description {
  margin: 12px 0 0;
  color: #6b7c93;
  font-size: 11px;
  line-height: 1.65;
}
.cover-workbench :deep(.cover-upload) {
  display: grid;
  gap: 11px;
  align-items: stretch;
}
.cover-workbench :deep(.cover-preview) {
  display: grid;
  gap: 8px;
  align-items: start;
}
.cover-workbench :deep(.cover-image) {
  width: 100%;
  height: auto;
  aspect-ratio: 16 / 9;
  object-fit: cover;
}
.cover-workbench :deep(.el-upload),
.cover-workbench :deep(.el-upload .el-button) {
  display: block;
  width: 100%;
}
.cover-workbench :deep(.cover-hint) {
  display: block;
  line-height: 1.55;
}
.settings-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-top: 2px;
}
.save-note {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  color: #78909c;
  font-size: 11px;
}
.save-note svg {
  width: 14px;
  color: #2ca6a4;
}
.row-end {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.new-stage {
  padding: 1px 0 20px;
  margin-bottom: 18px;
  border-bottom: 1px solid #dfe9ee;
}
.stage-form-heading {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 16px;
}
.stage-form-heading .section-title {
  margin-bottom: 11px;
}
.stage-form-heading p {
  margin: 0;
  color: #829ab1;
  font-size: 11px;
}
.new-stage-form {
  display: grid;
  grid-template-columns: minmax(180px, 0.8fr) minmax(220px, 1.35fr) auto;
  gap: 14px;
  align-items: end;
}
.new-stage-form :deep(.el-form-item) {
  min-width: 0;
  margin-bottom: 0;
}
.new-stage-form :deep(.el-input) {
  width: 100%;
}
.stage-action-field :deep(.el-form-item__content) {
  justify-content: flex-end;
}
.stage-heading {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 10px;
}
.stage-heading h3 {
  margin: 0;
  color: #243b53;
  font-size: 15px;
}
.stage-heading span {
  display: block;
  margin-top: 3px;
  color: #829ab1;
  font-size: 12px;
}
.stages {
  display: grid;
  gap: 12px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.stage {
  display: grid;
  gap: 10px;
  padding: 14px;
  border: 1px solid #e1eaf0;
  border-radius: 7px;
  background: #fbfdfe;
}
.stage-head {
  display: flex;
  gap: 8px;
  align-items: center;
}
.stage-title {
  min-width: 0;
  flex: 1;
}
.stage-order {
  width: 88px;
}
.stage-copy h3,
.stage-copy p {
  margin: 0;
}
.stage-copy h3 {
  color: #243b53;
  font-size: 15px;
}
.stage-copy p {
  margin-top: 4px;
  color: #6b7c93;
  font-size: 12px;
}
.stage-summary {
  display: block;
}
.stage-summary :deep(.el-form-item) {
  margin-bottom: 0;
}
.course-list {
  display: grid;
  gap: 6px;
  padding: 0;
  margin: 0;
  list-style: none;
}
.course {
  display: flex;
  min-width: 0;
  gap: 9px;
  align-items: center;
  padding: 8px 10px;
  border: 1px solid #e5edf2;
  border-radius: 6px;
  background: #fff;
}
.course-icon {
  display: grid;
  width: 25px;
  height: 25px;
  flex: 0 0 25px;
  place-items: center;
  border-radius: 6px;
  color: #168da7;
  background: #e7f6f8;
  font-size: 14px;
}
.course-copy {
  display: grid;
  min-width: 0;
  flex: 1;
  gap: 2px;
}
.course-title {
  overflow-wrap: anywhere;
  color: #243b53;
  font-size: 13px;
  font-weight: 600;
}
.course-meta {
  color: #829ab1;
  font-size: 11px;
}
.add-course {
  padding: 10px 12px;
  border: 1px dashed #b9dfe3;
  border-radius: 7px;
  background: #f7fcfc;
}
.add-course-form :deep(.el-form-item) {
  margin-bottom: 0;
}
.add-course-form :deep(.el-select) {
  width: min(100%, 300px);
}
.right-pane :deep(.el-empty) {
  min-height: 320px;
  justify-content: center;
}
.right-pane :deep(.el-alert) {
  margin-bottom: 14px;
}
@media (max-width: 1000px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 860px) {
  .settings-layout {
    grid-template-columns: 1fr;
  }
  .cover-workbench {
    display: grid;
    grid-template-columns: minmax(0, 1fr) minmax(180px, 230px);
    gap: 0 16px;
    align-items: center;
  }
  .cover-workbench-head {
    grid-row: span 2;
    margin-bottom: 0;
  }
  .cover-description {
    grid-column: 2;
    margin-top: 10px;
  }
}
@media (max-width: 700px) {
  .settings-grid {
    grid-template-columns: 1fr;
  }
  .settings-grid .settings-wide {
    grid-column: auto;
  }
  .settings-heading,
  .stage-form-heading {
    align-items: flex-start;
    flex-direction: column;
    gap: 7px;
  }
  .settings-caption {
    align-self: flex-start;
  }
  .cover-workbench {
    display: block;
  }
  .cover-workbench-head {
    margin-bottom: 13px;
  }
  .cover-description {
    margin-top: 12px;
  }
  .new-stage-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 0;
  }
  .new-stage-form :deep(.el-input) {
    width: 100%;
  }
  .new-stage-form :deep(.el-form-item) {
    margin-bottom: 12px;
  }
}
@media (max-width: 560px) {
  .filter-form {
    display: grid;
    grid-template-columns: 1fr;
    gap: 4px;
  }
  .filter-form :deep(.el-form-item) {
    display: grid;
    grid-template-columns: 76px minmax(0, 1fr);
    align-items: center;
  }
  .filter-control {
    width: 100%;
  }
  .stage-head {
    align-items: stretch;
    flex-wrap: wrap;
  }
  .stage-title {
    width: 100%;
    flex-basis: 100%;
  }
  .stage-order {
    width: 110px;
  }
}
</style>
