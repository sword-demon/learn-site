<template>
  <main class="page lesson-page">
    <p v-if="loading" class="notice">课节加载中…</p>
    <p v-else-if="loadError" class="notice error">
      {{ errorMessage || '课节暂时读不到, 请稍后再试.' }}
    </p>
    <template v-else-if="delivery">
      <header class="lesson-head">
        <div class="head-text">
          <p class="badge">
            <router-link :to="`/courses/${courseId}`">← 返回课程</router-link>
          </p>
          <h1 class="display">{{ deliveryTitle }}</h1>
          <p class="lede">{{ kindLabel }}</p>
        </div>
        <nav class="pager" aria-label="上下节">
          <button v-if="prev" type="button" class="btn" @click="goSibling(prev)">上一节</button>
          <button v-if="next" type="button" class="btn" @click="goSibling(next)">下一节</button>
        </nav>
      </header>

      <MarkdownRenderer v-if="delivery.kind === 'markdown'" :html="delivery.html" />
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

      <section
        v-if="delivery.kind === 'markdown' || delivery.kind === 'pdf'"
        class="lesson-completion"
        aria-live="polite"
      >
        <p v-if="completed" class="notice success">本节已完成.</p>
        <button
          v-else
          type="button"
          class="btn btn-primary"
          :disabled="completionPending"
          @click="completeLesson"
        >
          {{ completionPending ? '提交中…' : '标记为已完成' }}
        </button>
        <p v-if="completionError" class="notice error">{{ completionError }}</p>
      </section>

      <QuestionPanel :lesson-id="lessonId" />
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
      return '图文课节';
    case 'pdf':
      return 'PDF 课节';
    case 'video':
      return '视频课节';
    default:
      return '';
  }
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
    courseChapters.value = detail.chapters;
    for (const ch of detail.chapters) {
      for (const ls of ch.lessons) {
        if (ls.id === lessonId.value) {
          deliveryTitle.value = ls.title;
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
  gap: 24px;
}

.lesson-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 20px;
  padding: 22px 0 25px;
  border-bottom: 1px solid var(--line);
}

.head-text .display {
  max-width: 18ch;
  margin: 7px 0 7px;
  color: var(--pine-deep);
  font-size: 2.55rem;
}

.lede {
  margin: 0;
  color: var(--muted);
}

.badge a {
  color: inherit;
  text-decoration: none;
}

.pager {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.rich-html,
.asset-block {
  width: min(820px, 100%);
  margin: 0 auto;
  padding: 28px 32px 34px;
  border-top: 3px solid var(--pine);
  border-bottom: 1px solid var(--line);
  background: rgba(255, 254, 250, 0.78);
}

.asset-block {
  display: grid;
  justify-items: start;
  gap: 14px;
}

.asset-block p {
  margin: 0;
  color: var(--muted);
  line-height: 1.7;
}

.lesson-completion {
  display: grid;
  justify-items: start;
  gap: 9px;
}

.player {
  display: block;
  width: 100%;
  max-height: 70vh;
  background: #15201c;
  border: 1px solid var(--line);
  border-radius: 4px;
}

@media (max-width: 560px) {
  .lesson-head {
    align-items: start;
    flex-direction: column;
  }

  .head-text .display {
    font-size: 2.2rem;
  }

  .rich-html,
  .asset-block {
    padding: 22px 17px 26px;
  }
}
</style>
