<template>
  <main class="page lesson-page">
    <p v-if="loading" class="notice">课节加载中…</p>
    <p v-else-if="loadError" class="notice error">
      {{ errorMessage || '课节暂时读不到, 请稍后再试.' }}
    </p>
    <template v-else-if="delivery">
      <header class="learn-top">
        <h2>{{ courseTitle || deliveryTitle }}</h2>
        <div class="pbar">
          <div class="mini-progress" style="flex: 1">
            <i :style="{ width: `${positionPercent}%` }" />
          </div>
          <span>{{ positionLabel }}</span>
        </div>
        <router-link :to="`/courses/${courseId}`" class="btn btn-ghost btn-sm">返回课程</router-link>
      </header>

      <div class="learn-grid">
        <div class="panel stage">
          <div class="stage-head">
            <h3>{{ deliveryTitle }}</h3>
            <span class="typechip" :class="typechipClass">{{ kindLabel }}</span>
          </div>
          <p class="stage-sub">{{ chapterLabel }}</p>

          <div v-if="delivery.kind === 'markdown'" class="article">
            <MarkdownRenderer :html="delivery.html" />
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

          <div
            v-if="delivery.kind === 'markdown' || delivery.kind === 'pdf'"
            class="stage-foot"
            aria-live="polite"
          >
            <span class="muted small">{{ completed ? '本节已完成' : '阅读后请标记完成' }}</span>
            <span class="spacer" />
            <button v-if="prev" type="button" class="btn btn-ghost btn-sm" @click="goSibling(prev)">
              ← 上一节
            </button>
            <button v-if="next" type="button" class="btn btn-ghost btn-sm" @click="goSibling(next)">
              下一节 →
            </button>
            <button
              type="button"
              class="btn"
              :class="completed ? 'btn-ghost' : 'btn-primary'"
              :disabled="completionPending || completed"
              @click="completeLesson"
            >
              {{ completionPending ? '提交中…' : completed ? '✓ 已完成' : '标记本节完成' }}
            </button>
            <p v-if="completionError" class="notice error">{{ completionError }}</p>
          </div>

          <div v-else class="stage-foot">
            <span class="spacer" />
            <button v-if="prev" type="button" class="btn btn-ghost btn-sm" @click="goSibling(prev)">
              ← 上一节
            </button>
            <button v-if="next" type="button" class="btn btn-ghost btn-sm" @click="goSibling(next)">
              下一节 →
            </button>
          </div>

          <QuestionPanel :lesson-id="lessonId" />
        </div>

        <aside class="panel sidecat" aria-label="课程目录">
          <h4>课程目录</h4>
          <template v-for="(chapter, chapterIndex) in courseChapters" :key="chapter.id">
            <div class="chapter-name">第 {{ chapterIndex + 1 }} 章 · {{ chapter.title }}</div>
            <router-link
              v-for="lesson in chapter.lessons"
              :key="lesson.id"
              :to="`/learn/${courseId}/${lesson.id}`"
              class="slesson"
              :class="{ cur: lesson.id === lessonId }"
            >
              <span>
                {{ lesson.title }}
                <span v-if="lesson.is_preview" class="tag tag-trial" style="font-size: 10px; padding: 0 5px">
                  试
                </span>
              </span>
              <span class="st">{{ lesson.locked ? '🔒' : lesson.id === lessonId ? '·' : '' }}</span>
            </router-link>
          </template>
        </aside>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type {
  ChapterWithLessonSummariesDTO,
  LessonDeliveryDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';
import { fetchCourseDetail, fetchLesson, fetchMediaObjectUrl } from '@/api/learner';
import { useLearningProgress } from '@/composables/useLearningProgress';
import QuestionPanel from '@/views/learn/QuestionPanel.vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import PdfViewer from '@/components/PdfViewer.vue';
import VideoPlayer from '@/components/VideoPlayer.vue';

defineOptions({ name: 'LessonView' });

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
const completionPending = ref(false);
const completed = ref(false);
const completionError = ref('');
const mediaObjectUrl = ref('');

const courseId = computed(() => Number(route.params.courseId));
const lessonId = computed(() => Number(route.params.lessonId));
const learningProgress = useLearningProgress(lessonId);

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

const flatLessons = computed(() => {
  const items: Array<{ id: number; title: string }> = [];
  for (const chapter of courseChapters.value) {
    for (const lesson of chapter.lessons) {
      items.push({ id: lesson.id, title: lesson.title });
    }
  }
  return items;
});

const positionPercent = computed(() => {
  const total = flatLessons.value.length;
  if (!total) return 0;
  const index = flatLessons.value.findIndex((lesson) => lesson.id === lessonId.value);
  if (index < 0) return 0;
  return Math.round(((index + 1) / total) * 100);
});

const positionLabel = computed(() => {
  const total = flatLessons.value.length;
  const index = flatLessons.value.findIndex((lesson) => lesson.id === lessonId.value);
  if (index < 0 || !total) return '课节进度';
  return `${index + 1}/${total} 节`;
});

interface Sibling {
  courseId: number;
  lessonId: number;
  title: string;
}

function findSibling(direction: -1 | 1): Sibling | null {
  const flat: Array<{ id: number; title: string }> = [];
  for (const ch of courseChapters.value) {
    for (const ls of ch.lessons) {
      flat.push({ id: ls.id, title: ls.title });
    }
  }
  const idx = flat.findIndex((l) => l.id === lessonId.value);
  if (idx < 0) return null;
  const target = flat[idx + direction];
  if (!target) return null;
  return { courseId: courseId.value, lessonId: target.id, title: target.title };
}

function goSibling(s: Sibling): void {
  router.push(`/learn/${s.courseId}/${s.lessonId}`);
}

async function loadCourseMeta(): Promise<void> {
  try {
    const detail: PublicCourseDetailDTO = await fetchCourseDetail(courseId.value);
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
    // Detail is optional metadata for pager + title; lesson still loads.
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
    errorMessage.value = '无效的课节地址.';
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
      errorMessage.value = '这节课需要先获得访问权, 请回到课程页.';
    } else if (code === 'TOKEN_EXPIRED' || code === 'UNAUTHENTICATED') {
      errorMessage.value = '登录状态已过期, 请重新登录.';
    } else if (code === 'NOT_FOUND') {
      errorMessage.value = '课节不存在或已下架.';
    } else {
      errorMessage.value = '课节暂时读不到, 请稍后再试.';
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
  } catch {
    completionError.value = '完成状态提交失败, 请稍后重试.';
  } finally {
    completionPending.value = false;
  }
}

async function openPdf(): Promise<void> {
  if (delivery.value?.kind !== 'pdf') return;
  // Open in a new tab and report the open event so the lesson can be
  // marked complete later. The server only marks md/pdf complete once
  // the lesson has been opened at least once (rule in ProgressService).
  if (!mediaObjectUrl.value) {
    completionError.value = 'PDF 资源暂时不可用.';
    return;
  }
  window.open(mediaObjectUrl.value, '_blank', 'noopener,noreferrer');
  try {
    const progress = await learningProgress.reportDocumentOpen('pdf');
    completed.value = progress.completed;
  } catch {
    /* best-effort */
  }
}

function onVideoTimeUpdate(event: Event): void {
  const video = event.currentTarget as HTMLVideoElement | null;
  if (delivery.value?.kind !== 'video' || !video) return;
  // Throttle: report every ~30 seconds while playing.
  if (videoTimer !== null) return;
  videoTimer = window.setTimeout(() => {
    videoTimer = null;
    if (!video || delivery.value?.kind !== 'video') return;
    void learningProgress
      .heartbeat(Math.floor(video.currentTime || 0), Math.floor(video.duration || 0))
      .then((progress) => {
        completed.value = progress.completed;
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
    })
    .catch(() => undefined);
}

onUnmounted(() => {
  if (videoTimer !== null) {
    window.clearTimeout(videoTimer);
    videoTimer = null;
  }
  replaceMediaObjectUrl('');
});

function replaceMediaObjectUrl(next: string): void {
  if (mediaObjectUrl.value) {
    URL.revokeObjectURL(mediaObjectUrl.value);
  }
  mediaObjectUrl.value = next;
}

onMounted(async () => {
  await Promise.all([loadCourseMeta(), loadLesson()]);
  // For markdown lessons we record an open event so they can be marked
  // complete on a subsequent explicit "complete" report. The server
  // only accepts completed=true for md/pdf once opened_at is set.
  if (delivery.value?.kind === 'markdown' && !markdownOpened) {
    markdownOpened = true;
    try {
      const progress = await learningProgress.reportDocumentOpen('markdown');
      completed.value = progress.completed;
    } catch {
      /* best-effort */
    }
  }
});
</script>

<style scoped>
.lesson-page {
  display: grid;
  gap: 0;
}

.article :deep(.rich-html) {
  max-width: none;
  padding: 0;
  border: 0;
  background: transparent;
}
</style>
