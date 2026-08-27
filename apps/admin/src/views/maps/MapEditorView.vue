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

const statusOptions: Array<{ value: '' | MapStatus; label: string }> = [
  { value: '', label: '全部' },
  { value: 'draft', label: '草稿' },
  { value: 'published', label: '已发布' },
];
const filterStatus = ref<'' | MapStatus>('');
const availableCourses = computed(() => {
  const usedCourseIds = new Set(
    active.value?.stages.flatMap((stage) => stage.courses.map((step) => step.course_id)) ?? [],
  );
  return courses.value.filter((course) => !usedCourseIds.has(course.id));
});

async function loadList(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    list.value = await listMaps({
      ...(filterStatus.value === '' ? {} : { status: filterStatus.value }),
      page: 1,
      limit: 50,
    });
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
  active.value = detail;
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
  if (active.value.status === 'draft' && active.value.publish_issues.length > 0) return;
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

function updateStageSummary(stageId: number, event: Event): void {
  const value = (event.target as HTMLTextAreaElement).value.trim();
  void updateStageField(stageId, { summary: value || null });
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
    <header class="head">
      <h1 class="display">学习地图</h1>
      <label class="filter">
        状态
        <select v-model="filterStatus">
          <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>
      </label>
    </header>

    <p v-if="loading" class="notice">加载中…</p>
    <p v-else-if="loadError" class="notice error">暂时读不到 ({{ loadError }}).</p>

    <div class="layout">
      <aside class="left-pane">
        <form v-if="canManage" data-role="new-map" class="new-map" @submit.prevent="submitCreate">
          <h2>新建地图</h2>
          <label>
            部门
            <select v-model.number="newMap.department_id">
              <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
            </select>
          </label>
          <label>
            标题
            <input v-model="newMap.title" type="text" maxlength="128" />
          </label>
          <label>
            简介
            <input v-model="newMap.summary" type="text" maxlength="255" />
          </label>
          <button type="submit" class="btn btn-primary" :disabled="submitting">创建草稿</button>
        </form>

        <h2>地图列表</h2>
        <ol v-if="list?.items?.length" class="map-list">
          <li
            v-for="m in list.items"
            :key="m.id"
            class="map-item"
            :class="{ active: active && active.id === m.id }"
          >
            <button
              type="button"
              class="map-button"
              @click="router.replace({ query: { id: String(m.id) } })"
            >
              <strong>{{ m.title }}</strong>
              <span class="badge" :data-status="m.status">{{ statusLabel(m.status) }}</span>
            </button>
          </li>
        </ol>
        <p v-else class="notice">暂无地图.</p>
      </aside>

      <article class="right-pane">
        <p v-if="!active" class="notice">从左侧选择地图开始编辑.</p>
        <p v-else-if="activeError" class="notice error">{{ activeError }}</p>
        <template v-else>
          <header class="map-head">
            <div>
              <h2>{{ active.title }}</h2>
              <p v-if="active.summary" class="summary">{{ active.summary }}</p>
              <p v-if="active.objective" class="map-meta">目标：{{ active.objective }}</p>
              <p v-if="active.audience" class="map-meta">适用人群：{{ active.audience }}</p>
            </div>
            <div class="actions">
              <span class="badge" :data-status="active.status">{{
                statusLabel(active.status)
              }}</span>
              <button
                v-if="canPublish"
                type="button"
                class="btn"
                data-action="publish"
                :disabled="
                  submitting || (active.status === 'draft' && active.publish_issues?.length > 0)
                "
                @click="publishToggle"
              >
                {{ active.status === 'published' ? '下架' : '发布' }}
              </button>
              <button
                v-if="canManage && active.status === 'draft'"
                type="button"
                class="btn btn-danger"
                :disabled="submitting"
                @click="destroyActive"
              >
                删除草稿
              </button>
            </div>
          </header>

          <section
            v-if="active.publish_issues?.length"
            data-role="publish-issues"
            class="publish-issues"
            aria-labelledby="publish-issues-title"
          >
            <h3 id="publish-issues-title">发布前需要处理</h3>
            <ul>
              <li
                v-for="issue in active.publish_issues"
                :key="`${issue.code}-${issue.stage_id}-${issue.course_id}`"
              >
                {{ publishIssueLabel(issue) }}
              </li>
            </ul>
          </section>

          <form
            v-if="canManage"
            data-role="map-settings"
            class="map-settings"
            @submit.prevent="submitMapSettings"
          >
            <h3>地图设置</h3>
            <div class="form-grid">
              <label>
                所属部门
                <select v-model.number="mapSettings.department_id" name="department_id">
                  <option v-for="d in departments" :key="d.id" :value="d.id">{{ d.name }}</option>
                </select>
              </label>
              <label>
                标题
                <input v-model="mapSettings.title" name="title" type="text" maxlength="128" />
              </label>
              <label class="wide">
                简介
                <input v-model="mapSettings.summary" name="summary" type="text" maxlength="255" />
              </label>
              <label class="wide">
                封面地址
                <input
                  v-model="mapSettings.cover_url"
                  name="cover_url"
                  type="url"
                  maxlength="255"
                />
              </label>
              <label>
                学习目标
                <textarea v-model="mapSettings.objective" name="objective" rows="3"></textarea>
              </label>
              <label>
                适用人群
                <textarea v-model="mapSettings.audience" name="audience" rows="3"></textarea>
              </label>
            </div>
            <div class="row-end">
              <button
                type="submit"
                class="btn btn-primary"
                :disabled="submitting || !mapSettings.title.trim()"
              >
                保存设置
              </button>
            </div>
          </form>

          <form
            v-if="canManage"
            data-role="new-stage"
            class="new-stage"
            @submit.prevent="submitStage"
          >
            <label>
              新阶段标题
              <input v-model="newStage.title" type="text" maxlength="128" />
            </label>
            <label>
              简介
              <input v-model="newStage.summary" type="text" maxlength="255" />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="submitting">添加阶段</button>
          </form>

          <ol class="stages">
            <li
              v-for="stage in active.stages"
              :key="stage.id"
              class="stage"
              :data-stage-id="stage.id"
            >
              <header class="stage-head">
                <template v-if="canManage">
                  <input
                    class="stage-title"
                    :value="stage.title"
                    type="text"
                    maxlength="128"
                    @change="
                      updateStageField(stage.id, {
                        title: ($event.target as HTMLInputElement).value,
                      })
                    "
                  />
                  <input
                    class="stage-order"
                    :value="stage.sort_order"
                    type="number"
                    min="1"
                    @change="
                      updateStageField(stage.id, {
                        sort_order: Number(($event.target as HTMLInputElement).value),
                      })
                    "
                  />
                  <button
                    type="button"
                    class="btn btn-danger"
                    data-action="delete-stage"
                    :disabled="submitting"
                    @click="removeStage(stage.id)"
                  >
                    删除
                  </button>
                </template>
                <div v-else class="stage-copy">
                  <h3>{{ stage.title }}</h3>
                  <p v-if="stage.summary">{{ stage.summary }}</p>
                </div>
              </header>
              <label v-if="canManage" class="stage-summary">
                阶段说明
                <textarea
                  name="stage_summary"
                  rows="2"
                  :value="stage.summary ?? ''"
                  @change="updateStageSummary(stage.id, $event)"
                ></textarea>
              </label>
              <ol v-if="stage.courses?.length" class="course-list">
                <li v-for="sc in stage.courses" :key="sc.map_stage_course_id" class="course">
                  <span class="course-title">{{
                    sc.course?.title ?? `课程 #${sc.course_id}`
                  }}</span>
                  <span v-if="sc.course" class="course-meta">
                    {{ sc.course.teacher_name }} · {{ sc.course.status }}
                  </span>
                  <button
                    v-if="canManage"
                    type="button"
                    class="btn btn-danger"
                    data-action="remove-course"
                    :disabled="submitting"
                    @click="dropCourse(stage.id, sc.course_id)"
                  >
                    移除
                  </button>
                </li>
              </ol>
              <p v-else class="notice">该阶段暂无课程.</p>

              <form
                v-if="canManage && addCourseStageId === stage.id"
                class="add-course"
                @submit.prevent="submitAddCourse"
              >
                <label>
                  选择课程
                  <select v-model.number="addCourseId" name="course_id">
                    <option :value="0" disabled>请选择课程</option>
                    <option v-for="c in availableCourses" :key="c.id" :value="c.id">
                      {{ c.title }}
                    </option>
                  </select>
                </label>
                <div class="row-end">
                  <button type="button" class="btn" @click="addCourseStageId = null">取消</button>
                  <button type="submit" class="btn btn-primary" :disabled="submitting">添加</button>
                </div>
              </form>
              <button
                v-else-if="canManage"
                type="button"
                class="btn"
                data-action="add-course"
                :disabled="submitting || availableCourses.length === 0"
                @click="
                  addCourseStageId = stage.id;
                  addCourseId = 0;
                "
              >
                + 添加课程
              </button>
            </li>
          </ol>
        </template>
      </article>
    </div>
  </section>
</template>

<style scoped>
.page {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}
.display {
  margin: 0;
  font-size: 1.4rem;
}
.filter select {
  padding: 4px 8px;
}
.layout {
  display: grid;
  grid-template-columns: minmax(260px, 360px) 1fr;
  gap: 16px;
}
@media (max-width: 1000px) {
  .layout {
    grid-template-columns: 1fr;
  }
}
.left-pane,
.right-pane {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 8px;
  padding: 16px;
  background: #fff;
}
.new-map,
.new-stage {
  display: grid;
  gap: 8px;
  margin-bottom: 12px;
}
.new-map label,
.new-stage label,
.add-course label,
.filter {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.new-map input,
.new-map select,
.new-stage input,
.add-course select,
.stage-title,
.stage-order,
.filter select {
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
.map-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 8px;
}
.map-item {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
}
.map-item.active {
  border-color: var(--color-primary, #2563eb);
}
.map-button {
  width: 100%;
  text-align: left;
  background: transparent;
  border: 0;
  padding: 10px 12px;
  cursor: pointer;
  font: inherit;
  display: flex;
  gap: 8px;
  align-items: center;
}
.badge {
  padding: 2px 8px;
  border-radius: 999px;
  background: var(--color-bg-soft, #f5f6fa);
  border: 1px solid var(--color-border, #d0d4dc);
  font-size: 0.78rem;
}
.badge[data-status='published'] {
  background: #e7f7ee;
  border-color: #2bb673;
}
.badge[data-status='draft'] {
  background: #f0f1f3;
  border-color: #c5c8d0;
}
.map-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}
.map-head h2 {
  margin: 0;
}
.actions {
  display: flex;
  gap: 8px;
  align-items: center;
}
.summary {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.map-meta {
  color: var(--color-text-muted, #5b6472);
  margin: 4px 0 0;
  font-size: 0.9rem;
}
.map-settings {
  display: grid;
  gap: 12px;
  margin-bottom: 16px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--color-border, #d0d4dc);
}
.map-settings h3 {
  margin: 0;
  font-size: 1rem;
}
.publish-issues {
  margin-bottom: 16px;
  padding: 12px;
  border-left: 3px solid #b54708;
  background: #fff7ed;
  color: #7a2e0e;
}
.publish-issues h3 {
  margin: 0 0 6px;
  font-size: 0.95rem;
}
.publish-issues ul {
  margin: 0;
  padding-left: 20px;
}
.form-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}
.form-grid label {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.form-grid .wide {
  grid-column: 1 / -1;
}
.form-grid input,
.form-grid select,
.form-grid textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
@media (max-width: 700px) {
  .form-grid {
    grid-template-columns: 1fr;
  }
  .form-grid .wide {
    grid-column: auto;
  }
}
.stages {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 12px;
}
.stage {
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  padding: 12px;
  display: grid;
  gap: 8px;
}
.stage-head {
  display: flex;
  gap: 8px;
  align-items: center;
}
.stage-copy h3,
.stage-copy p {
  margin: 0;
}
.stage-copy p {
  margin-top: 4px;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.9rem;
}
.stage-summary {
  display: grid;
  gap: 4px;
  font-size: 0.9rem;
}
.stage-summary textarea {
  width: 100%;
  box-sizing: border-box;
  padding: 6px 8px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  font: inherit;
}
.stage-title {
  flex: 1;
}
.stage-order {
  width: 80px;
}
.course-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 6px;
}
.course {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 8px;
  align-items: center;
  padding: 6px 10px;
  background: var(--color-bg-soft, #fafbfd);
  border-radius: 6px;
}
.course-meta {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.btn {
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn-danger {
  background: #b42318;
  color: #fff;
  border-color: transparent;
}
.row-end {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}
.notice {
  color: var(--color-text-muted, #5b6472);
  margin: 0;
}
.notice.error {
  color: #b42318;
}
.add-course {
  display: grid;
  gap: 8px;
  padding: 8px;
  border: 1px dashed var(--color-border, #d0d4dc);
  border-radius: 6px;
}
</style>
