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
      <p>地图暂时读不到，请稍后再试。</p>
      <button type="button" class="btn btn-ghost" data-action="retry" @click="load">
        重新加载
      </button>
    </div>
    <template v-else-if="detail">
      <p class="badge">
        <router-link to="/maps">学习地图</router-link> <span aria-hidden="true">/</span>
        {{ detail.title }}
      </p>
      <section class="map-hero">
        <div class="map-hero-copy">
          <p class="eyebrow"><span class="eyebrow-rule" />学习路径 · 分阶段推进</p>
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
        <div class="map-hero-side">
          <div v-if="detail.cover_url" class="cover-wrap">
            <img class="cover" :src="detail.cover_url" :alt="coverAlt(detail.title)" />
          </div>
          <div class="progress-panel">
            <template v-if="progress">
              <p class="progress-label">当前进度</p>
              <p class="progress-line">
                进度 {{ progress.completed_courses }} / {{ progress.total_courses }} ({{
                  progress.progress_percent
                }}%)
              </p>
              <progress :value="progress.completed_courses" :max="progress.total_courses" />
            </template>
            <template v-else>
              <p class="progress-label">准备好开始了吗？</p>
              <p class="progress-hint">加入后，课程会按照下面的阶段逐步解锁。</p>
              <button
                type="button"
                class="btn btn-primary"
                data-action="start-map"
                :disabled="enrolling"
                @click="enroll"
              >
                {{ enrolling ? '加入中…' : '加入学习地图' }}
              </button>
            </template>
          </div>
        </div>
      </section>

      <p v-if="enrollError" class="notice error">加入失败，请稍后再试。</p>
      <div v-if="nextStep" class="next-step">
        <div><span class="next-kicker">建议下一步</span><strong>继续完成当前路径</strong></div>
        <router-link
          :to="`/courses/${nextStep.course_id}`"
          data-action="next-step"
          class="btn btn-primary"
        >
          继续下一步 <span aria-hidden="true">→</span>
        </router-link>
      </div>

      <section class="stage-section">
        <header class="section-heading">
          <div>
            <p class="eyebrow"><span class="eyebrow-rule" />路径目录</p>
            <h2 class="display">一段一段，走完它</h2>
          </div>
          <span class="stage-count">{{ detail.stages.length }} 个阶段</span>
        </header>
        <ol class="stages">
          <li v-for="stage in detail.stages" :key="stage.id" class="stage">
            <header class="stage-head">
              <span class="stage-number latin">{{
                String(stage.sort_order).padStart(2, '0')
              }}</span>
              <div>
                <h3>{{ stage.title }}</h3>
                <p v-if="stage.summary" class="summary">{{ stage.summary }}</p>
              </div>
            </header>
            <ol class="courses">
              <li v-for="sc in stage.courses" :key="sc.map_stage_course_id" class="course">
                <router-link
                  v-if="sc.available"
                  :to="`/courses/${sc.course_id}`"
                  class="course-link"
                  :data-authorized="sc.viewer_authorized"
                >
                  <span class="course-marker" :class="{ completed: sc.completed }">{{
                    sc.completed ? '✓' : ''
                  }}</span>
                  <strong>{{ courseTitle(sc) }}</strong>
                  <span v-if="sc.course" class="teacher">{{ sc.course.teacher_name }}</span>
                  <span v-if="sc.completed" class="course-state">已完成</span>
                  <span v-else-if="!sc.viewer_authorized" class="course-state">未获得访问权</span>
                </router-link>
                <div v-else class="course-link unavailable" aria-disabled="true">
                  <span class="course-marker" />
                  <strong>{{ courseTitle(sc) }}</strong>
                  <span v-if="sc.course" class="teacher">{{ sc.course.teacher_name }}</span>
                  <span class="course-state">课程已下架</span>
                  <span v-if="sc.completed" class="course-state">已完成</span>
                </div>
              </li>
            </ol>
          </li>
        </ol>
      </section>
    </template>
  </main>
</template>

<style scoped>
.map-detail {
  display: grid;
  gap: 28px;
}

.map-detail > .badge {
  margin-bottom: -10px;
}

.map-hero {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(330px, 0.72fr);
  gap: 48px;
  align-items: start;
  padding: 26px 0 40px;
  border-bottom: 1px solid var(--line);
}

.map-hero-copy .display {
  max-width: 15ch;
  margin: 0 0 13px;
  color: var(--pine-deep);
  font-size: 3rem;
}

.summary {
  margin: 0;
  color: var(--muted);
  line-height: 1.7;
}

.details {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: 7px 12px;
  margin: 26px 0 0;
  font-size: 0.82rem;
}

.details dt {
  color: var(--muted);
}

.details dd {
  min-width: 0;
  margin: 0;
}

.map-hero-side {
  display: grid;
  gap: 18px;
}

.cover-wrap {
  aspect-ratio: 1.55;
  overflow: hidden;
  border: 1px solid var(--line);
  background: var(--paper-deep);
  box-shadow:
    12px 12px 0 var(--paper-deep),
    var(--shadow);
  transform: rotate(-1deg);
}

.cover {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.progress-panel {
  display: grid;
  gap: 7px;
  padding: 18px;
  border-left: 3px solid var(--accent);
  background: var(--surface-muted);
}

.progress-label,
.progress-line,
.progress-hint {
  margin: 0;
}

.progress-label {
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.06em;
}

.progress-line {
  color: var(--pine-deep);
  font-size: 0.9rem;
  font-weight: 700;
}

.progress-hint {
  color: var(--muted);
  font-size: 0.8rem;
  line-height: 1.6;
}

progress {
  width: 100%;
  height: 8px;
  accent-color: var(--accent);
}

.next-step {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  padding: 15px 18px;
  border: 1px solid var(--line);
  background: var(--surface);
}

.next-step div {
  display: grid;
  gap: 4px;
}

.next-kicker {
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 700;
}

.stage-section {
  display: grid;
  gap: 23px;
}

.section-heading {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 16px;
}

.section-heading .display {
  margin: 5px 0 0;
  color: var(--pine-deep);
  font-size: 1.65rem;
}

.stage-count {
  color: var(--muted);
  font-size: 0.8rem;
}

.stages {
  display: grid;
  gap: 16px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.stage {
  padding: 20px 21px 21px;
  border-top: 3px solid var(--pine);
  border-bottom: 1px solid var(--line);
  background: rgba(255, 254, 250, 0.7);
}

.stage-head {
  display: flex;
  gap: 14px;
  align-items: start;
}

.stage-number {
  padding-top: 2px;
  color: var(--accent);
  font-size: 0.76rem;
  font-weight: 700;
}

.stage-head h3 {
  margin: 0;
  color: var(--pine-deep);
  font-family: var(--font-display);
  font-size: 1.25rem;
}

.stage-head .summary {
  margin-top: 4px;
  font-size: 0.8rem;
}

.courses {
  display: grid;
  gap: 2px;
  margin: 17px 0 0;
  padding: 0;
  list-style: none;
}

.course-link {
  display: flex;
  align-items: center;
  gap: 11px;
  min-height: 48px;
  padding: 8px 9px;
  color: inherit;
  text-decoration: none;
  transition:
    background-color 0.2s ease,
    padding-left 0.2s ease;
}

.course-link:hover {
  padding-left: 13px;
  background: var(--surface-muted);
}

.course-link.unavailable {
  color: var(--muted);
  cursor: not-allowed;
}

.course-marker {
  display: inline-flex;
  width: 18px;
  height: 18px;
  flex-shrink: 0;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--line);
  border-radius: 50%;
  color: #fffefa;
  background: var(--surface);
  font-size: 0.68rem;
}

.course-marker.completed {
  border-color: var(--pine);
  background: var(--pine);
}

.teacher {
  color: var(--muted);
  font-size: 0.78rem;
}

.course-state {
  margin-left: auto;
  color: var(--muted);
  font-size: 0.76rem;
  white-space: nowrap;
}

@media (max-width: 820px) {
  .map-hero {
    grid-template-columns: 1fr;
    gap: 32px;
  }

  .map-hero-side {
    max-width: 520px;
  }
}

@media (max-width: 560px) {
  .map-hero-copy .display {
    font-size: 2.35rem;
  }

  .next-step,
  .section-heading {
    align-items: start;
    flex-direction: column;
  }

  .next-step .btn {
    width: 100%;
  }

  .stage {
    padding: 17px 13px;
  }

  .course-link {
    align-items: start;
    flex-wrap: wrap;
  }

  .course-state {
    margin-left: 29px;
  }
}
</style>
