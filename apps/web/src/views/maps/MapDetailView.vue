<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Check } from '@element-plus/icons-vue';
import { fetchLearningMap, startLearningMap } from '@/api/learner';
import { hasTokens } from '@/api/http';
import { loginPathFor } from '@/router/guards';
import { useMapLearningState } from '@/composables/useMapLearningState';
import type { LearnerMapDetailDTO, MapCourseStepDTO } from '@learn-site/contracts';

defineOptions({ name: 'MapDetailView' });

const route = useRoute();
const router = useRouter();
const id = computed(() => Number(route.params.id));
const detail = ref<LearnerMapDetailDTO | null>(null);
const loading = ref(false);
const loadError = ref(false);
const enrolling = ref(false);
const enrollError = ref<string | null>(null);

const { stageStates } = useMapLearningState(detail);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = false;
  try {
    detail.value = await fetchLearningMap(id.value);
  } catch {
    loadError.value = true;
    detail.value = null;
  } finally {
    loading.value = false;
  }
}

async function enroll(): Promise<void> {
  if (enrolling.value) return;
  if (!hasTokens()) {
    await router.push(loginPathFor(route.fullPath));
    return;
  }
  enrollError.value = null;
  enrolling.value = true;
  try {
    detail.value = await startLearningMap(id.value);
  } catch {
    enrollError.value = '加入失败，请稍后再试。';
  } finally {
    enrolling.value = false;
  }
}

watch(
  id,
  () => {
    void load();
  },
  { immediate: true },
);

const progress = computed(() => detail.value?.enrollment ?? null);
const nextStep = computed(() => detail.value?.next_step ?? null);

function courseTitle(step: MapCourseStepDTO): string {
  return step.course?.title ?? `课程 #${step.course_id}`;
}

function isNextStep(step: MapCourseStepDTO): boolean {
  return nextStep.value?.map_stage_course_id === step.map_stage_course_id;
}

function stepStateLabel(step: MapCourseStepDTO): string {
  if (step.completed) return '已完成';
  if (!step.available) return '已下架';
  if (step.viewer_authorized) return '学习中';
  return '未获得访问权';
}

function stepStateTagType(step: MapCourseStepDTO): 'success' | 'info' | 'warning' {
  if (step.completed) return 'success';
  if (!step.available) return 'info';
  if (step.viewer_authorized) return 'warning';
  return 'info';
}

function stepActionLabel(step: MapCourseStepDTO): string {
  if (step.completed) return '复习';
  if (step.viewer_authorized) return '继续';
  return '查看';
}

function stageStateTag(state: 'completed' | 'active' | 'locked'): string {
  if (state === 'completed') return '已完成';
  if (state === 'active') return '当前阶段';
  return '待解锁';
}

function stageStateType(state: 'completed' | 'active' | 'locked'): 'success' | 'warning' | 'info' {
  if (state === 'completed') return 'success';
  if (state === 'active') return 'warning';
  return 'info';
}

function goCourse(courseId: number): void {
  void router.push(`/courses/${courseId}`);
}
</script>

<template>
  <main class="page map-detail">
    <el-skeleton v-if="loading" animated :rows="7" />

    <div v-else-if="loadError" class="map-detail__error">
      <el-alert title="地图暂时读不到，请稍后再试。" type="error" :closable="false" show-icon />
      <el-button data-action="retry" @click="load">重新加载</el-button>
    </div>

    <template v-else-if="detail">
      <nav class="crumbs" aria-label="面包屑">
        <router-link to="/maps">学习地图</router-link>
        <span class="sep">/</span>
        <span>{{ detail.title }}</span>
      </nav>

      <header class="map-detail__hero">
        <div>
          <h1 class="map-detail__title">{{ detail.title }}</h1>
          <p class="map-detail__lede">
            {{ detail.objective || detail.summary || '按阶段推进学习路径。' }}
          </p>
          <p v-if="detail.audience" class="map-detail__audience">适合：{{ detail.audience }}</p>
        </div>
        <div class="map-detail__hero-cta">
          <el-button
            v-if="!progress"
            type="primary"
            data-action="start-map"
            :loading="enrolling"
            @click="enroll"
          >
            开始这条路径
          </el-button>
          <el-tag v-else type="success" effect="light">已加入</el-tag>
        </div>
      </header>

      <section v-if="progress" class="map-detail__progress">
        <el-progress :percentage="progress.progress_percent" :show-text="false" :stroke-width="6" />
        <span class="map-detail__progress-label">
          {{ progress.completed_courses }}/{{ progress.total_courses }} 门 ·
          {{ progress.progress_percent }}%
        </span>
      </section>

      <router-link
        v-if="nextStep"
        :to="`/courses/${nextStep.course_id}`"
        class="map-detail__next-cta"
        data-action="next-step"
      >
        继续下一步 →
      </router-link>

      <el-alert
        v-if="enrollError"
        title="加入失败，请稍后再试。"
        type="error"
        :closable="false"
        show-icon
      />

      <p v-if="!progress" class="form-note">
        加入后开始记录你的路径进度；收费课程仍需单独购买，开始路径不会自动授予访问权。
      </p>

      <div class="map-detail__grid">
        <aside class="map-detail__timeline" aria-label="阶段时间轴">
          <ol class="stage-line">
            <li
              v-for="(stage, stageIndex) in detail.stages"
              :key="stage.id"
              class="stage-node"
              :data-state="stageStates[stageIndex] || 'locked'"
              :data-stage-id="stage.id"
            >
              <div class="stage-dot">
                <el-icon v-if="stageStates[stageIndex] === 'completed'"><Check /></el-icon>
                <span v-else>{{ stageIndex + 1 }}</span>
              </div>
              <div class="stage-info">
                <h3>{{ stage.title }}</h3>
                <p v-if="stage.summary" class="stage-summary">{{ stage.summary }}</p>
                <el-tag
                  size="small"
                  :type="stageStateType(stageStates[stageIndex] || 'locked')"
                  effect="plain"
                  class="stage-state-tag"
                >
                  {{ stageStateTag(stageStates[stageIndex] || 'locked') }}
                </el-tag>
              </div>
            </li>
          </ol>
        </aside>

        <section class="map-detail__stages" aria-label="阶段详情">
          <article
            v-for="(stage, stageIndex) in detail.stages"
            :key="stage.id"
            class="stage-block"
            :data-stage-id="stage.id"
          >
            <header class="stage-block__head">
              <h2>阶段 {{ stageIndex + 1 }} · {{ stage.title }}</h2>
              <el-tag
                size="small"
                :type="stageStateType(stageStates[stageIndex] || 'locked')"
                effect="light"
              >
                {{ stageStateTag(stageStates[stageIndex] || 'locked') }}
              </el-tag>
            </header>

            <ul v-if="stage.courses.length > 0" class="course-list">
              <li
                v-for="step in stage.courses"
                :key="step.map_stage_course_id"
                class="course-row"
                :data-state="
                  step.completed
                    ? 'done'
                    : step.viewer_authorized
                      ? 'active'
                      : step.available
                        ? 'available'
                        : 'unavailable'
                "
                :data-step-id="step.map_stage_course_id"
              >
                <div class="course-row__title">
                  <span class="course-row__index">STEP</span>
                  <el-button
                    v-if="step.available"
                    link
                    class="course-row__name"
                    @click="goCourse(step.course_id)"
                  >
                    《{{ courseTitle(step) }}》
                  </el-button>
                  <span v-else class="course-row__name">《{{ courseTitle(step) }}》</span>
                  <span v-if="isNextStep(step)" class="course-row__next-chip">建议下一步</span>
                </div>
                <el-tag :type="stepStateTagType(step)" size="small" effect="plain">
                  {{ stepStateLabel(step) }}
                </el-tag>
                <el-button v-if="step.available" size="small" @click="goCourse(step.course_id)">
                  {{ stepActionLabel(step) }}
                </el-button>
                <span v-else class="course-row__locked">不可学习</span>
              </li>
            </ul>
            <el-empty v-else description="这一阶段还没有课程" :image-size="60" />
          </article>
        </section>
      </div>
    </template>
  </main>
</template>

<style scoped>
.map-detail {
  padding-bottom: 48px;
}

.map-detail__error {
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
}

.crumbs {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 13px;
  color: var(--ink-2, #606266);
}

.crumbs a {
  color: var(--ink-2, #606266);
  text-decoration: none;
}

.crumbs a:hover {
  color: var(--seal, #409eff);
}

.crumbs .sep {
  color: var(--ink-2, #c0c4cc);
}

.map-detail__hero {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 24px;
  margin: 12px 0 20px;
}

.map-detail__title {
  margin: 0 0 8px;
  font-size: 24px;
  color: var(--ink, #303133);
}

.map-detail__lede {
  margin: 0;
  max-width: 720px;
  font-size: 14px;
  color: var(--ink-2, #606266);
  line-height: 1.6;
}

.map-detail__audience {
  margin: 6px 0 0;
  font-size: 13px;
  color: var(--ink-2, #909399);
}

.map-detail__hero-cta {
  flex-shrink: 0;
}

.map-detail__progress {
  display: flex;
  gap: 12px;
  align-items: center;
  padding: 12px 16px;
  background: var(--card-2, #f5f7fa);
  border-radius: var(--r, 8px);
  margin-bottom: 16px;
}

.map-detail__progress :deep(.el-progress) {
  flex: 1;
}

.map-detail__progress-label {
  font-size: 13px;
  color: var(--ink-2, #606266);
  white-space: nowrap;
}

.map-detail__next-cta {
  display: inline-block;
  margin-bottom: 24px;
  padding: 8px 16px;
  background: var(--seal, #409eff);
  color: #fff;
  border-radius: var(--r, 6px);
  text-decoration: none;
  font-size: 14px;
}

.map-detail__next-cta:hover {
  opacity: 0.92;
}

.map-detail__grid {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 32px;
  margin-top: 24px;
}

.stage-line {
  list-style: none;
  margin: 0;
  padding: 0;
  position: relative;
}

.stage-line::before {
  content: '';
  position: absolute;
  left: 14px;
  top: 14px;
  bottom: 14px;
  width: 2px;
  background: var(--line, #ebeef5);
}

.stage-node {
  position: relative;
  display: flex;
  gap: 12px;
  padding: 12px 0;
}

.stage-dot {
  position: relative;
  z-index: 1;
  flex-shrink: 0;
  width: 30px;
  height: 30px;
  border-radius: 50%;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 13px;
  font-weight: 600;
  color: var(--ink-2, #909399);
  background: var(--card-2, #f5f7fa);
  border: 2px solid var(--line, #ebeef5);
}

.stage-node[data-state='completed'] .stage-dot {
  background: var(--success, #67c23a);
  border-color: var(--success, #67c23a);
  color: #fff;
}

.stage-node[data-state='active'] .stage-dot {
  background: var(--warning, #e6a23c);
  border-color: var(--warning, #e6a23c);
  color: #fff;
}

.stage-info {
  flex: 1;
  min-width: 0;
}

.stage-info h3 {
  margin: 4px 0 4px;
  font-size: 14px;
  color: var(--ink, #303133);
}

.stage-summary {
  margin: 0 0 6px;
  font-size: 12px;
  color: var(--ink-2, #909399);
  line-height: 1.5;
}

.stage-state-tag {
  font-size: 11px;
}

.stage-block {
  margin-bottom: 32px;
  padding-bottom: 24px;
  border-bottom: 1px solid var(--line, #ebeef5);
}

.stage-block:last-child {
  border-bottom: none;
}

.stage-block__head {
  display: flex;
  justify-content: space-between;
  align-items: baseline;
  margin-bottom: 12px;
}

.stage-block__head h2 {
  margin: 0;
  font-size: 16px;
  color: var(--ink, #303133);
}

.course-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.course-row {
  display: grid;
  grid-template-columns: 1fr auto auto;
  gap: 16px;
  align-items: center;
  padding: 12px 16px;
  border: 1px solid var(--line, #ebeef5);
  border-radius: var(--r, 8px);
  background: #fff;
}

.course-row[data-state='done'] {
  background: var(--success-soft, #f0f9eb);
}

.course-row[data-state='active'] {
  border-color: var(--warning, #e6a23c);
}

.course-row__title {
  display: flex;
  align-items: center;
  gap: 10px;
  min-width: 0;
}

.course-row__index {
  font-size: 11px;
  font-weight: 600;
  color: var(--ink-2, #c0c4cc);
  letter-spacing: 0.5px;
}

.course-row__name {
  font-size: 14px;
  color: var(--ink, #303133);
  margin: 0;
  padding: 0;
}

.course-row__next-chip {
  font-size: 11px;
  padding: 2px 8px;
  background: var(--seal-soft, #ecf5ff);
  color: var(--seal, #409eff);
  border-radius: 10px;
}

.course-row__locked {
  font-size: 12px;
  color: var(--ink-2, #c0c4cc);
}
</style>
