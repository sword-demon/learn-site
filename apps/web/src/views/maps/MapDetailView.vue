<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchLearningMap, startLearningMap } from '@/api/learner';
import { hasTokens } from '@/api/http';
import { loginPathFor } from '@/router/guards';
import type { LearnerMapDetailDTO, MapCourseStepDTO } from '@learn-site/contracts';

defineOptions({ name: 'MapDetailView' });

const route = useRoute();
const router = useRouter();
const id = computed(() => Number(route.params.id));
const detail = ref<LearnerMapDetailDTO | null>(null);
const loading = ref(false);
const loadError = ref<string | null>(null);
const enrolling = ref(false);
const enrollError = ref<string | null>(null);

async function load(): Promise<void> {
  loading.value = true;
  loadError.value = null;
  try {
    detail.value = await fetchLearningMap(id.value);
  } catch (err) {
    loadError.value = (err as Error).message || 'load_failed';
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
  } catch (err) {
    enrollError.value = (err as Error).message || 'ENROLL_FAILED';
  } finally {
    enrolling.value = false;
  }
}

watch(id, load, { immediate: true });

const progress = computed(() => detail.value?.enrollment ?? null);
const nextStep = computed(() => detail.value?.next_step ?? null);

function courseTitle(step: MapCourseStepDTO): string {
  return step.course?.title ?? `课程 #${step.course_id}`;
}

function isNextStep(step: MapCourseStepDTO): boolean {
  return nextStep.value?.map_stage_course_id === step.map_stage_course_id;
}

function stepRowClass(step: MapCourseStepDTO): Record<string, boolean> {
  return {
    done: step.completed,
    next: isNextStep(step),
  };
}

function stepStateLabel(step: MapCourseStepDTO): string {
  if (step.completed) return '✓ 已完成';
  if (!step.available) return '已下架';
  if (step.viewer_authorized) return '学习中';
  return '未获得访问权';
}

function stepStateTagClass(step: MapCourseStepDTO): string {
  if (step.completed) return 'tag-free';
  if (!step.available) return 'tag-lock';
  if (step.viewer_authorized) return 'tag-on';
  return 'tag-lock';
}

function stepActionLabel(step: MapCourseStepDTO): string {
  if (step.completed) return '复习';
  if (step.viewer_authorized) return '继续';
  return '查看';
}

function goCourse(courseId: number): void {
  void router.push(`/courses/${courseId}`);
}
</script>

<template>
  <main class="page map-detail">
    <p v-if="loading" class="notice">地图加载中…</p>
    <div v-else-if="loadError" class="notice error">
      <p>地图暂时读不到，请稍后再试。</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">重新加载</button>
    </div>
    <template v-else-if="detail">
      <nav class="crumbs" aria-label="面包屑">
        <router-link to="/maps">学习地图</router-link>
        <span class="sep">/</span>
        <span>{{ detail.title }}</span>
      </nav>

      <div class="list-head" style="margin-top: 12px">
        <h2>{{ detail.title }}</h2>
        <button
          v-if="!progress"
          type="button"
          class="btn btn-primary btn-sm"
          data-action="start-map"
          :disabled="enrolling"
          @click="enroll"
        >
          {{ enrolling ? '加入中…' : '开始这条路径' }}
        </button>
        <button v-else type="button" class="btn btn-ghost btn-sm" disabled>已加入</button>
      </div>

      <p class="muted" style="max-width: 680px; margin: 0">
        {{ detail.objective || detail.summary || '按阶段推进学习路径。' }}
      </p>
      <p v-if="detail.audience" class="small muted" style="margin-bottom: 6px">
        适合：{{ detail.audience }}
      </p>

      <div
        v-if="progress"
        class="learn-top"
        style="border-bottom-width: 1px; margin-bottom: 20px"
      >
        <div class="pbar">
          <div class="mini-progress" style="flex: 1">
            <i :style="{ width: `${progress.progress_percent}%` }" />
          </div>
          <span
            >{{ progress.completed_courses }}/{{ progress.total_courses }} 门 ·
            {{ progress.progress_percent }}%</span
          >
        </div>
      </div>

      <router-link
        v-if="nextStep"
        :to="`/courses/${nextStep.course_id}`"
        class="btn btn-primary btn-sm"
        data-action="next-step"
        style="width: fit-content; margin-bottom: 8px"
      >
        继续下一步 →
      </router-link>

      <p v-if="enrollError" class="notice error">加入失败，请稍后再试。</p>
      <p v-if="!progress" class="form-note">
        加入后开始记录你的路径进度；收费课程仍需单独购买，开始路径不会自动授予访问权。
      </p>

      <div class="stage-line fade">
        <div v-for="(stage, stageIndex) in detail.stages" :key="stage.id" class="stage-node">
          <div class="stage-dot">{{ stageIndex + 1 }}</div>
          <div>
            <h4>{{ stage.title }}</h4>
            <p v-if="stage.summary" class="sdesc">{{ stage.summary }}</p>
            <div
              v-for="step in stage.courses"
              :key="step.map_stage_course_id"
              class="step-row"
              :class="stepRowClass(step)"
            >
              <div>
                <span class="sn">STEP</span>
                <button
                  v-if="step.available"
                  type="button"
                  class="sc"
                  @click="goCourse(step.course_id)"
                >
                  《{{ courseTitle(step) }}》
                </button>
                <span v-else class="sc">《{{ courseTitle(step) }}》</span>
                <span v-if="isNextStep(step)" class="next-chip">建议下一步</span>
              </div>
              <div>
                <span class="tag" :class="stepStateTagClass(step)">{{ stepStateLabel(step) }}</span>
              </div>
              <div>
                <button
                  v-if="step.available"
                  type="button"
                  class="btn btn-ghost btn-sm"
                  @click="goCourse(step.course_id)"
                >
                  {{ stepActionLabel(step) }}
                </button>
                <span v-else class="small muted">不可学习</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </main>
</template>
