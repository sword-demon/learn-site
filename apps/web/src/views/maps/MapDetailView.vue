<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, Refresh, VideoPlay } from '@element-plus/icons-vue';
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

function goCourse(courseId: number): void {
  void router.push(`/courses/${courseId}`);
}
</script>

<template>
  <main class="page map-detail">
    <el-skeleton v-if="loading" animated :rows="7" />
    <div v-else-if="loadError" class="notice error">
      <el-alert title="地图暂时读不到，请稍后再试。" type="error" :closable="false" show-icon />
      <el-button :icon="Refresh" data-action="retry" @click="load">重新加载</el-button>
    </div>
    <template v-else-if="detail">
      <nav class="crumbs" aria-label="面包屑">
        <router-link to="/maps">学习地图</router-link>
        <span class="sep">/</span>
        <span>{{ detail.title }}</span>
      </nav>

      <div class="list-head" style="margin-top: 12px">
        <h2>{{ detail.title }}</h2>
        <el-button
          v-if="!progress"
          type="primary"
          size="small"
          :icon="ArrowRight"
          data-action="start-map"
          :loading="enrolling"
          @click="enroll"
        >
          开始这条路径
        </el-button>
        <el-button v-else size="small" disabled>已加入</el-button>
      </div>

      <p class="muted" style="max-width: 680px; margin: 0">
        {{ detail.objective || detail.summary || '按阶段推进学习路径。' }}
      </p>
      <p v-if="detail.audience" class="small muted" style="margin-bottom: 6px">
        适合：{{ detail.audience }}
      </p>

      <div v-if="progress" class="learn-top" style="border-bottom-width: 1px; margin-bottom: 20px">
        <div class="pbar">
          <el-progress style="flex: 1" :percentage="progress.progress_percent" :show-text="false" />
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
                <el-button v-if="step.available" link class="sc" @click="goCourse(step.course_id)">
                  《{{ courseTitle(step) }}》
                </el-button>
                <span v-else class="sc">《{{ courseTitle(step) }}》</span>
                <span v-if="isNextStep(step)" class="next-chip">建议下一步</span>
              </div>
              <div>
                <el-tag :type="stepStateTagType(step)" size="small" effect="plain">
                  {{ stepStateLabel(step) }}
                </el-tag>
              </div>
              <div>
                <el-button
                  v-if="step.available"
                  size="small"
                  :icon="VideoPlay"
                  @click="goCourse(step.course_id)"
                >
                  {{ stepActionLabel(step) }}
                </el-button>
                <span v-else class="small muted">不可学习</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </template>
  </main>
</template>
