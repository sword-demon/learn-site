<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { fetchLearningMap, startLearningMap } from '@/api/learner';
import { hasTokens } from '@/api/http';
import { loginPathFor } from '@/router/guards';
import type { LearnerMapDetailDTO } from '@learn-site/contracts';

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

function coverAlt(title: string): string {
  return `${title}封面`;
}

function courseTitle(course: LearnerMapDetailDTO['stages'][number]['courses'][number]): string {
  return course.course?.title ?? `课程 #${course.course_id}`;
}
</script>

<template>
  <main class="page map-detail">
    <p v-if="loading" class="notice">地图加载中…</p>
    <div v-else-if="loadError" class="notice error">
      <p>地图暂时读不到 ({{ loadError }}).</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">重试</button>
    </div>
    <template v-else-if="detail">
      <div v-if="detail.cover_url" class="cover-wrap">
        <img class="cover" :src="detail.cover_url" :alt="coverAlt(detail.title)" />
      </div>
      <header class="head">
        <div>
          <h1 class="display">{{ detail.title }}</h1>
          <p v-if="detail.summary" class="summary">{{ detail.summary }}</p>
          <dl class="details">
            <template v-if="detail.objective">
              <dt>学习目标</dt>
              <dd>{{ detail.objective }}</dd>
            </template>
            <template v-if="detail.audience">
              <dt>适合人群</dt>
              <dd>{{ detail.audience }}</dd>
            </template>
          </dl>
        </div>
        <div class="progress">
          <template v-if="progress">
            <p class="progress-line">
              进度 {{ progress.completed_courses }} / {{ progress.total_courses }} ({{
                progress.progress_percent
              }}%)
            </p>
            <progress :value="progress.completed_courses" :max="progress.total_courses" />
          </template>
          <button
            v-else
            type="button"
            class="btn btn-primary"
            data-action="start-map"
            :disabled="enrolling"
            @click="enroll"
          >
            {{ enrolling ? '加入中…' : '加入地图' }}
          </button>
        </div>
      </header>
      <p v-if="enrollError" class="error">加入失败 ({{ enrollError }}).</p>
      <div v-if="nextStep" class="next-step">
        <span>建议下一步</span>
        <router-link
          :to="`/courses/${nextStep.course_id}`"
          data-action="next-step"
          class="btn btn-primary"
        >
          继续下一步
        </router-link>
      </div>

      <ol class="stages">
        <li v-for="stage in detail.stages" :key="stage.id" class="stage">
          <header class="stage-head">
            <h2>{{ stage.sort_order }}. {{ stage.title }}</h2>
            <p v-if="stage.summary" class="summary">{{ stage.summary }}</p>
          </header>
          <ol class="courses">
            <li v-for="sc in stage.courses" :key="sc.map_stage_course_id" class="course">
              <router-link
                v-if="sc.available"
                :to="`/courses/${sc.course_id}`"
                class="course-link"
                :data-authorized="sc.viewer_authorized"
              >
                <strong>{{ courseTitle(sc) }}</strong>
                <span v-if="sc.course" class="teacher">{{ sc.course.teacher_name }}</span>
                <span v-if="sc.completed" class="course-state">已完成</span>
                <span v-else-if="!sc.viewer_authorized" class="course-state">未获得访问权</span>
              </router-link>
              <div v-else class="course-link unavailable" aria-disabled="true">
                <strong>{{ courseTitle(sc) }}</strong>
                <span v-if="sc.course" class="teacher">{{ sc.course.teacher_name }}</span>
                <span class="course-state">课程已下架</span>
                <span v-if="sc.completed" class="course-state">已完成</span>
              </div>
            </li>
          </ol>
        </li>
      </ol>
    </template>
  </main>
</template>

<style scoped>
.map-detail {
  display: grid;
  gap: 16px;
}
.head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  gap: 12px;
  flex-wrap: wrap;
}
.display {
  margin: 0;
}
.summary {
  color: var(--color-text-muted, #5b6472);
  margin: 4px 0 0 0;
}
.cover-wrap {
  max-width: 920px;
}
.cover {
  width: 100%;
  aspect-ratio: 16 / 5;
  object-fit: cover;
  border-radius: 8px;
  background: var(--color-bg-soft, #f5f6fa);
}
.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 4px 8px;
  margin: 10px 0 0;
  font-size: 0.9rem;
}
.details dt {
  color: var(--color-text-muted, #5b6472);
}
.details dd {
  margin: 0;
}
.progress {
  display: grid;
  gap: 6px;
}
.progress-line {
  font-size: 0.9rem;
  margin: 0;
}
progress {
  width: 220px;
  height: 10px;
}
.btn {
  padding: 6px 14px;
  border-radius: 6px;
  border: 1px solid var(--color-border, #d0d4dc);
  background: transparent;
  font: inherit;
  cursor: pointer;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
  border-color: transparent;
}
.btn-ghost {
  color: inherit;
}
.next-step {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 12px;
  border-left: 3px solid var(--color-primary, #2563eb);
  background: var(--color-bg-soft, #f7f8fb);
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
  border-radius: 8px;
  padding: 12px 16px;
}
.stage-head h2 {
  margin: 0;
  font-size: 1.05rem;
}
.courses {
  list-style: none;
  padding: 0;
  margin: 8px 0 0 0;
  display: grid;
  gap: 6px;
}
.course {
  padding: 8px 10px;
  border-radius: 6px;
  background: var(--color-bg-soft, #fafbfd);
}
.course-link {
  display: flex;
  gap: 12px;
  align-items: center;
  color: inherit;
  text-decoration: none;
}
.course-link.unavailable {
  color: var(--color-text-muted, #5b6472);
  cursor: not-allowed;
}
.teacher {
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.course-state {
  margin-left: auto;
  color: var(--color-text-muted, #5b6472);
  font-size: 0.85rem;
}
.notice {
  color: var(--color-text-muted, #5b6472);
}
.notice.error {
  color: #b42318;
}
.error {
  color: #b42318;
}
</style>
