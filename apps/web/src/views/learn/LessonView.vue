<template>
  <main class="page lesson-page" :data-course-id="courseId" :data-lesson-id="lessonId">
    <el-skeleton v-if="loading" animated :rows="9" />

    <div v-else-if="loadError" class="lesson-page__error">
      <el-alert
        :title="errorMessage || '课节暂时读不到，请稍后再试。'"
        type="error"
        :closable="false"
        show-icon
      />
      <el-button data-action="retry" @click="bootstrapLesson">重新加载</el-button>
    </div>

    <template v-else-if="delivery">
      <header class="lesson-top">
        <div class="lesson-top__title">
          <h2 class="lesson-top__h">{{ courseTitle || deliveryTitle }}</h2>
          <p class="lesson-top__crumb">{{ chapterLabel }}</p>
        </div>
        <div class="lesson-top__progress" aria-label="课节进度">
          <el-progress class="lesson-top__bar" :percentage="positionPercent" :show-text="false" />
          <span class="lesson-top__position">{{ positionLabel }}</span>
          <router-link :to="`/courses/${courseId}`" class="lesson-top__back">返回课程</router-link>
        </div>
      </header>

      <div class="lesson-grid">
        <aside class="lesson-catalog" aria-label="课程目录">
          <h4 class="lesson-catalog__h">课程目录</h4>
          <template v-for="(chapter, chapterIndex) in courseChapters" :key="chapter.id">
            <div class="lesson-catalog__chapter">
              第 {{ chapterIndex + 1 }} 章 · {{ chapter.title }}
            </div>
            <router-link
              v-for="lesson in chapter.lessons"
              :key="lesson.id"
              :to="`/learn/${courseId}/${lesson.id}`"
              class="lesson-catalog__lesson"
              :class="{ 'lesson-catalog__lesson--cur': lesson.id === lessonId }"
              :data-lesson-id="lesson.id"
            >
              <span class="lesson-catalog__lesson-title">
                {{ lesson.title }}
                <el-tag v-if="lesson.is_preview" type="warning" size="small" effect="plain">
                  试
                </el-tag>
              </span>
              <el-icon v-if="lesson.locked" class="lesson-catalog__lock" aria-label="已锁定">
                <Lock />
              </el-icon>
              <span v-else-if="lesson.id === lessonId" class="lesson-catalog__marker">·</span>
            </router-link>
          </template>
        </aside>

        <section class="lesson-content">
          <article class="lesson-stage">
            <header class="lesson-stage__head">
              <h3 class="lesson-stage__title">{{ deliveryTitle }}</h3>
              <span class="lesson-stage__typechip" :class="typechipClass">{{ kindLabel }}</span>
            </header>
            <p class="lesson-stage__sub">{{ chapterLabel }}</p>

            <div v-if="delivery.kind === 'markdown'" class="lesson-stage__article">
              <MarkdownRenderer :html="delivery.html" />
              <!-- ponytail: Figma wants lesson.progress_seconds + current_position scrubber; DTO lacks runtime fields -->
            </div>
            <PdfViewer
              v-else-if="delivery.kind === 'pdf'"
              :url="mediaObjectUrl"
              :status="delivery.status"
              @open="openPdf"
            />
            <VideoPlayer
              v-else-if="delivery.kind === 'video'"
              :url="mediaObjectUrl"
              :status="delivery.status"
              @timeupdate="onVideoTimeUpdate"
              @ended="onVideoEnded"
            />

            <footer
              v-if="delivery.kind === 'markdown' || delivery.kind === 'pdf'"
              class="lesson-stage__foot"
              aria-live="polite"
            >
              <el-tag v-if="completed" type="success" size="small" effect="plain"
                >本节已完成</el-tag
              >
              <span v-else class="lesson-stage__hint">阅读后请标记完成</span>
              <span class="lesson-stage__spacer" />

              <el-button
                v-if="prev"
                size="small"
                :icon="ArrowLeft"
                data-action="previous-lesson"
                @click="goSibling(prev)"
              >
                上一节
              </el-button>
              <el-button
                v-if="next"
                size="small"
                data-action="next-lesson"
                @click="goSibling(next)"
              >
                下一节
                <el-icon class="el-icon--right"><ArrowRight /></el-icon>
              </el-button>
              <el-button
                :type="completed ? 'success' : 'primary'"
                :icon="Check"
                data-action="complete-lesson"
                :disabled="completionPending || completed"
                :loading="completionPending"
                @click="completeLesson"
              >
                {{ completed ? '已完成' : '标记本节完成' }}
              </el-button>
              <el-alert
                v-if="completionError"
                :title="completionError"
                type="error"
                :closable="false"
                show-icon
                class="lesson-stage__alert"
              />
            </footer>

            <footer v-else class="lesson-stage__foot">
              <span class="lesson-stage__spacer" />
              <el-button
                v-if="prev"
                size="small"
                :icon="ArrowLeft"
                data-action="previous-lesson"
                @click="goSibling(prev)"
              >
                上一节
              </el-button>
              <el-button
                v-if="next"
                size="small"
                data-action="next-lesson"
                @click="goSibling(next)"
              >
                下一节
                <el-icon class="el-icon--right"><ArrowRight /></el-icon>
              </el-button>
            </footer>

            <el-alert
              v-if="nextActionError"
              title="下一步暂时读不到，请从课程目录继续。"
              type="warning"
              :closable="false"
              show-icon
              data-testid="learning-action-error"
            />
            <div
              v-else-if="nextAction"
              class="lesson-next-action"
              data-testid="learning-action-next"
            >
              <span>
                <strong>{{ nextAction.title }}</strong>
                <small>{{ nextAction.reason }}</small>
              </span>
              <router-link
                v-if="nextAction.availability !== 'unavailable'"
                :to="nextAction.target.path"
              >
                继续
              </router-link>
            </div>
          </article>
        </section>

        <aside class="lesson-side" aria-label="问答与笔记">
          <QuestionPanel :lesson-id="lessonId" :authorized="qaAuthorized" />
          <!-- ponytail: Figma wants user_note + lesson.qna[]; QuestionPanel only renders Q&A; notes panel is separate component not yet designed -->
        </aside>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowLeft, ArrowRight, Check, Lock } from '@element-plus/icons-vue';
import type {
  ChapterWithLessonSummariesDTO,
  LessonDeliveryDTO,
  LearnerNextActionDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';
import { fetchCourseDetail, fetchLesson, fetchMediaObjectUrl } from '@/api/learner';
import { useLearningProgress } from '@/composables/useLearningProgress';
import { fetchNextAction } from '@/api/learningAction';
import { canParticipateInCourseQa } from '@/utils/courseAccess';
import QuestionPanel from '@/views/learn/QuestionPanel.vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import PdfViewer from '@/components/PdfViewer.vue';
import VideoPlayer from '@/components/VideoPlayer.vue';

defineOptions({ name: 'LessonView' });

interface Sibling {
  courseId: number;
  lessonId: number;
  title: string;
}

const route = useRoute();
const router = useRouter();
const delivery = ref<LessonDeliveryDTO | null>(null);
const loading = ref(true);
const loadError = ref(false);
const errorMessage = ref('');
const deliveryTitle = ref('');
const courseTitle = ref('');
const chapterLabel = ref('');
const courseChapters = ref<ChapterWithLessonSummariesDTO[]>([]);
const courseMeta = ref<PublicCourseDetailDTO['course'] | null>(null);
const completionPending = ref(false);
const completed = ref(false);
const completionError = ref('');
const mediaObjectUrl = ref('');
const nextAction = ref<LearnerNextActionDTO['action']>(null);
const nextActionError = ref(false);

// ponytail: H1 route-param guard via computed + Number.isFinite + business bounds
const courseId = computed(() => Number(route.params.courseId));
const lessonId = computed(() => Number(route.params.lessonId));
const learningProgress = useLearningProgress(lessonId);

// ponytail: collapsed two flat-loops (positionPercent + findSibling) into one shared flatLessons
const flatLessons = computed(() => {
  const items: Array<{ id: number; title: string }> = [];
  for (const chapter of courseChapters.value) {
    for (const lesson of chapter.lessons) {
      items.push({ id: lesson.id, title: lesson.title });
    }
  }
  return items;
});

const currentIndex = computed(() =>
  flatLessons.value.findIndex((lesson) => lesson.id === lessonId.value),
);

const positionPercent = computed(() => {
  const total = flatLessons.value.length;
  if (!total || currentIndex.value < 0) return 0;
  return Math.round(((currentIndex.value + 1) / total) * 100);
});

const positionLabel = computed(() => {
  const total = flatLessons.value.length;
  if (currentIndex.value < 0 || !total) return '课节进度';
  return `${currentIndex.value + 1}/${total} 节`;
});

const prev = computed<Sibling | null>(() => findSibling(-1));
const next = computed<Sibling | null>(() => findSibling(+1));

const kindLabel = computed(() => {
  if (!delivery.value) return '';
  switch (delivery.value.kind) {
    case 'markdown':
      return '图文';
    case 'pdf':
      return 'PDF';
    case 'video':
      return '视频';
    default:
      return '';
  }
});

const typechipClass = computed(() => {
  if (!delivery.value) return '';
  switch (delivery.value.kind) {
    case 'markdown':
      return 't-md';
    case 'pdf':
      return 't-pdf';
    case 'video':
      return 't-video';
    default:
      return '';
  }
});

const qaAuthorized = computed((): boolean => {
  if (delivery.value) return true;
  if (!courseMeta.value) return true;
  return canParticipateInCourseQa(courseMeta.value);
});

function findSibling(direction: -1 | 1): Sibling | null {
  const target = flatLessons.value[currentIndex.value + direction];
  if (!target || currentIndex.value < 0) return null;
  return { courseId: courseId.value, lessonId: target.id, title: target.title };
}

function goSibling(s: Sibling): void {
  void router.push(`/learn/${s.courseId}/${s.lessonId}`);
}

async function loadCourseMeta(): Promise<void> {
  try {
    const detail: PublicCourseDetailDTO = await fetchCourseDetail(courseId.value);
    courseMeta.value = detail.course;
    courseTitle.value = detail.course.title;
    courseChapters.value = detail.chapters;
    for (const ch of detail.chapters) {
      for (const ls of ch.lessons) {
        if (ls.id === lessonId.value) {
          deliveryTitle.value = ls.title;
          chapterLabel.value = `第 ${ch.sort + 1} 章 · ${ch.title}`;
          return;
        }
      }
    }
  } catch {
    /* ponytail: meta is optional; lesson still loads via loadLesson() */
  }
}

async function loadLesson(): Promise<void> {
  if (
    !Number.isFinite(courseId.value) ||
    courseId.value <= 0 ||
    !Number.isFinite(lessonId.value) ||
    lessonId.value <= 0
  ) {
    loadError.value = true;
    errorMessage.value = '无效的课节地址。';
    loading.value = false;
    return;
  }
  loading.value = true;
  loadError.value = false;
  errorMessage.value = '';
  try {
    delivery.value = await fetchLesson(courseId.value, lessonId.value);
    if (delivery.value.kind !== 'markdown' && delivery.value.status === 'ready') {
      replaceMediaObjectUrl(await fetchMediaObjectUrl(delivery.value.media_url));
    }
  } catch (err: unknown) {
    loadError.value = true;
    const code = (err as { code?: string }).code;
    if (code === 'FORBIDDEN') {
      errorMessage.value = '这节课需要先获得访问权，请回到课程页。';
    } else if (code === 'TOKEN_EXPIRED' || code === 'UNAUTHENTICATED') {
      errorMessage.value = '登录状态已过期，请重新登录。';
    } else if (code === 'NOT_FOUND') {
      errorMessage.value = '课节不存在或已下架。';
    } else {
      errorMessage.value = '课节暂时读不到，请稍后再试。';
    }
  } finally {
    loading.value = false;
  }
}

let videoTimer: number | null = null;
let markdownOpened = false;

async function completeLesson(): Promise<void> {
  if (!delivery.value || (delivery.value.kind !== 'markdown' && delivery.value.kind !== 'pdf'))
    return;
  completionPending.value = true;
  completionError.value = '';
  try {
    const progress = await learningProgress.completeDocument(delivery.value.kind);
    completed.value = progress.completed;
    if (progress.completed) await refreshNextAction();
  } catch {
    completionError.value = '完成状态提交失败，请稍后重试。';
  } finally {
    completionPending.value = false;
  }
}

async function refreshNextAction(): Promise<void> {
  nextActionError.value = false;
  try {
    const result = await fetchNextAction();
    nextAction.value = result.action ?? result.fallback;
  } catch {
    nextAction.value = null;
    nextActionError.value = true;
  }
}

async function openPdf(): Promise<void> {
  if (delivery.value?.kind !== 'pdf') return;
  if (!mediaObjectUrl.value) {
    completionError.value = 'PDF 资源暂时不可用。';
    return;
  }
  window.open(mediaObjectUrl.value, '_blank', 'noopener,noreferrer');
  try {
    const progress = await learningProgress.reportDocumentOpen('pdf');
    completed.value = progress.completed;
    if (progress.completed) void refreshNextAction();
  } catch {
    /* best-effort */
  }
}

function onVideoTimeUpdate(event: Event): void {
  const video = event.currentTarget as HTMLVideoElement | null;
  if (delivery.value?.kind !== 'video' || !video) return;
  if (videoTimer !== null) return;
  videoTimer = window.setTimeout(() => {
    videoTimer = null;
    if (!video || delivery.value?.kind !== 'video') return;
    void learningProgress
      .heartbeat(Math.floor(video.currentTime || 0), Math.floor(video.duration || 0))
      .then((progress) => {
        completed.value = progress.completed;
        if (progress.completed) void refreshNextAction();
      })
      .catch(() => undefined);
  }, 30_000) as unknown as number;
}

function onVideoEnded(event: Event): void {
  const video = event.currentTarget as HTMLVideoElement | null;
  if (delivery.value?.kind !== 'video' || !video) return;
  void learningProgress
    .heartbeat(Math.floor(video.duration || 0), Math.floor(video.duration || 0))
    .then((progress) => {
      completed.value = progress.completed;
      if (progress.completed) void refreshNextAction();
    })
    .catch(() => undefined);
}

function replaceMediaObjectUrl(next: string): void {
  if (mediaObjectUrl.value) {
    URL.revokeObjectURL(mediaObjectUrl.value);
  }
  mediaObjectUrl.value = next;
}

async function bootstrapLesson(): Promise<void> {
  markdownOpened = false;
  completed.value = false;
  completionError.value = '';
  await Promise.all([loadCourseMeta(), loadLesson()]);
  const loaded = delivery.value;
  if (loaded?.kind === 'markdown' && !markdownOpened) {
    markdownOpened = true;
    try {
      const progress = await learningProgress.reportDocumentOpen('markdown');
      completed.value = progress.completed;
      if (progress.completed) await refreshNextAction();
    } catch {
      /* best-effort */
    }
  }
}

onUnmounted(() => {
  if (videoTimer !== null) {
    window.clearTimeout(videoTimer);
    videoTimer = null;
  }
  replaceMediaObjectUrl('');
});

watch(
  () => [route.params.courseId, route.params.lessonId],
  () => {
    void bootstrapLesson();
  },
  { immediate: true },
);
</script>

<style scoped>
.lesson-next-action {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  margin-top: 16px;
  padding: 12px 14px;
  border-left: 3px solid var(--seal, #34566b);
  background: var(--paper, #fff);
}

.lesson-next-action span {
  display: grid;
  gap: 4px;
}

.lesson-next-action small {
  color: var(--ink-3, #6b7b6e);
}

.lesson-next-action a {
  flex: 0 0 auto;
  color: var(--seal, #34566b);
  font-weight: 700;
}

.lesson-page {
  display: grid;
  gap: 0;
  max-width: 1440px;
  padding-bottom: 24px;
}

.lesson-page__error {
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
}

.lesson-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid var(--line, #ebeef5);
}

.lesson-top__title {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.lesson-top__h {
  margin: 0;
  font-size: 18px;
  color: var(--ink, #303133);
}

.lesson-top__crumb {
  margin: 0;
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.lesson-top__progress {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-shrink: 0;
}

.lesson-top__bar {
  width: 200px;
}

.lesson-top__position {
  font-size: 12px;
  color: var(--ink-2, #606266);
  white-space: nowrap;
}

.lesson-top__back {
  font-size: 13px;
  color: var(--seal, #409eff);
  text-decoration: none;
}

.lesson-top__back:hover {
  opacity: 0.85;
}

.lesson-grid {
  display: grid;
  grid-template-columns: 280px minmax(0, 1fr) 320px;
  gap: 24px;
  margin-top: 16px;
  align-items: start;
  min-height: calc(100vh - 180px);
}

.lesson-catalog {
  padding: 0 0 8px;
  border: 1px solid var(--line-2);
  border-radius: 12px;
  background: var(--card);
  position: sticky;
  top: 80px;
  max-height: calc(100vh - 100px);
  overflow-y: auto;
  box-shadow: var(--shadow);
}

.lesson-catalog__h {
  margin: 0;
  padding: 16px;
  font-family: var(--serif);
  font-size: 24px;
  color: var(--ink);
  border-bottom: 1px solid var(--line-2);
}

.lesson-catalog__chapter {
  padding: 8px 0 4px;
  font-size: 12px;
  color: var(--ink-2, #c0c4cc);
  letter-spacing: 0.5px;
}

.lesson-catalog__lesson {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin: 0 8px;
  padding: 8px 10px;
  border-radius: var(--r, 6px);
  color: var(--ink-2, #606266);
  text-decoration: none;
  font-size: 13px;
  line-height: 1.4;
}

.lesson-catalog__lesson:hover {
  background: var(--card-2, #f5f7fa);
  color: var(--ink, #303133);
}

.lesson-catalog__lesson--cur {
  background: color-mix(in srgb, var(--seal) 8%, transparent);
  border-left: 2px solid var(--seal);
  color: var(--seal);
  font-weight: 600;
  border-radius: 0 8px 8px 0;
}

.lesson-catalog__lesson-title {
  flex: 1;
  min-width: 0;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.lesson-catalog__lock,
.lesson-catalog__marker {
  color: var(--ink-2, #c0c4cc);
}

.lesson-content {
  min-width: 0;
}

.lesson-stage {
  padding: 24px;
  border: 1px solid var(--line-2);
  border-radius: 12px;
  background: var(--card);
  box-shadow: var(--shadow);
}

.lesson-stage__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding-bottom: 8px;
  border-bottom: 1px dashed var(--line, #ebeef5);
}

.lesson-stage__title {
  margin: 0;
  font-family: var(--serif);
  font-size: 32px;
  color: var(--ink);
}

.lesson-stage__typechip {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  color: #fff;
  background: var(--ink-2, #909399);
}

.lesson-stage__typechip.t-md {
  background: var(--seal, #409eff);
}
.lesson-stage__typechip.t-pdf {
  background: #c45656;
}
.lesson-stage__typechip.t-video {
  background: var(--moss, #67c23a);
}

.lesson-stage__sub {
  margin: 6px 0 0;
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.lesson-stage__article {
  margin-top: 16px;
}

.lesson-stage__article :deep(.rich-html) {
  max-width: none;
  padding: 0;
  border: 0;
  background: transparent;
}

.lesson-stage__foot {
  margin-top: 24px;
  padding-top: 16px;
  border-top: 1px solid var(--line, #ebeef5);
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.lesson-stage__hint {
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.lesson-stage__spacer {
  flex: 1;
}

.lesson-stage__alert {
  width: 100%;
  margin-top: 8px;
}

.lesson-side {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 16px;
}
</style>
