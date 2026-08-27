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

      <section
        v-if="delivery.kind === 'markdown'"
        class="prose markdown-body"
        v-html="delivery.html"
      />

      <section v-else-if="delivery.kind === 'pdf'" class="asset-block">
        <p>这是一份 PDF 课节, 请点击下方按钮在新窗口打开.</p>
        <button type="button" class="btn btn-primary" @click="openPdf">查看 PDF</button>
        <p v-if="delivery.status !== 'ready'" class="notice">
          资源尚未处理完成 ({{ delivery.status }}), 可能无法打开.
        </p>
      </section>

      <section v-else-if="delivery.kind === 'video'" class="asset-block">
        <video
          ref="videoEl"
          controls
          preload="metadata"
          :src="delivery.storage_path"
          class="player"
        />
        <p v-if="delivery.status !== 'ready'" class="notice">
          资源尚未处理完成 ({{ delivery.status }}), 可能无法播放.
        </p>
      </section>

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
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type {
  ChapterWithLessonSummariesDTO,
  LessonDeliveryDTO,
  PublicCourseDetailDTO,
} from '@learn-site/contracts';
import { fetchCourseDetail, fetchLesson, reportLessonProgress } from '@/api/learner';
import QuestionPanel from '@/views/learn/QuestionPanel.vue';

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

const courseId = computed(() => Number(route.params.courseId));
const lessonId = computed(() => Number(route.params.lessonId));

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

const videoEl = ref<HTMLVideoElement | null>(null);
let videoTimer: number | null = null;
let markdownOpened = false;

async function completeLesson(): Promise<void> {
  if (!delivery.value || (delivery.value.kind !== 'markdown' && delivery.value.kind !== 'pdf'))
    return;
  completionPending.value = true;
  completionError.value = '';
  try {
    const progress = await reportLessonProgress(lessonId.value, {
      content_type: delivery.value.kind,
      position_seconds: 1,
      completed: true,
    });
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
  window.open(delivery.value.storage_path, '_blank', 'noopener,noreferrer');
  try {
    const progress = await reportLessonProgress(lessonId.value, {
      content_type: 'pdf',
      position_seconds: 1,
    });
    completed.value = progress.completed;
  } catch {
    /* best-effort */
  }
}

function onVideoTimeUpdate(): void {
  if (delivery.value?.kind !== 'video' || !videoEl.value) return;
  const dur = Math.floor(videoEl.value.duration || 0);
  const pos = Math.floor(videoEl.value.currentTime || 0);
  // Throttle: report every ~30 seconds while playing.
  if (videoTimer !== null) return;
  videoTimer = window.setTimeout(() => {
    videoTimer = null;
    if (!videoEl.value || delivery.value?.kind !== 'video') return;
    void reportLessonProgress(lessonId.value, {
      content_type: 'video',
      position_seconds: Math.floor(videoEl.value.currentTime || 0),
      duration_seconds: Math.floor(videoEl.value.duration || 0),
    }).catch(() => undefined);
  }, 30_000) as unknown as number;
  // Suppress the unused-var lint while keeping the read-side vars in scope.
  void dur;
  void pos;
}

watch(videoEl, (el) => {
  if (!el) return;
  el.addEventListener('timeupdate', onVideoTimeUpdate);
  el.addEventListener('ended', onVideoEnded);
});

function onVideoEnded(): void {
  if (delivery.value?.kind !== 'video' || !videoEl.value) return;
  void reportLessonProgress(lessonId.value, {
    content_type: 'video',
    position_seconds: Math.floor(videoEl.value.duration || 0),
    duration_seconds: Math.floor(videoEl.value.duration || 0),
  }).catch(() => undefined);
}

onUnmounted(() => {
  if (videoTimer !== null) {
    window.clearTimeout(videoTimer);
    videoTimer = null;
  }
  if (videoEl.value) {
    videoEl.value.removeEventListener('timeupdate', onVideoTimeUpdate);
    videoEl.value.removeEventListener('ended', onVideoEnded);
  }
});

onMounted(async () => {
  await Promise.all([loadCourseMeta(), loadLesson()]);
  // For markdown lessons we record an open event so they can be marked
  // complete on a subsequent explicit "complete" report. The server
  // only accepts completed=true for md/pdf once opened_at is set.
  if (delivery.value?.kind === 'markdown' && !markdownOpened) {
    markdownOpened = true;
    try {
      const progress = await reportLessonProgress(lessonId.value, {
        content_type: 'markdown',
        position_seconds: 1,
      });
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

.markdown-body,
.asset-block {
  width: min(820px, 100%);
  margin: 0 auto;
  padding: 28px 32px 34px;
  border-top: 3px solid var(--pine);
  border-bottom: 1px solid var(--line);
  background: rgba(255, 254, 250, 0.78);
}

.markdown-body {
  color: #34443d;
  line-height: 1.95;
}

.markdown-body :deep(h1),
.markdown-body :deep(h2),
.markdown-body :deep(h3) {
  color: var(--pine-deep);
  font-family: var(--font-display);
  line-height: 1.35;
}

.markdown-body :deep(pre) {
  overflow-x: auto;
  padding: 15px;
  border: 1px solid #24362f;
  border-radius: 5px;
  background: #1d2a25;
  color: #eff7ef;
}

.markdown-body :deep(code) {
  padding: 1px 4px;
  border-radius: 3px;
  background: var(--surface-muted);
  font-family: var(--font-mono);
  font-size: 0.9em;
}

.markdown-body :deep(pre code) {
  padding: 0;
  background: transparent;
}

.markdown-body :deep(blockquote) {
  margin: 20px 0;
  padding: 4px 0 4px 17px;
  border-left: 3px solid var(--accent);
  color: var(--muted);
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

  .markdown-body,
  .asset-block {
    padding: 22px 17px 26px;
  }
}
</style>
