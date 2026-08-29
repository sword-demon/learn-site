<template>
  <main class="page course-detail">
    <p v-if="loading" class="notice">课程加载中…</p>
    <p v-else-if="loadError" class="notice error">课程暂时读不到，请稍后再试。</p>
    <template v-else-if="detail">
      <nav class="crumbs" aria-label="面包屑">
        <router-link to="/">首页</router-link>
        <span class="sep">/</span>
        <span>{{ detail.course.category_name ?? '课程详情' }}</span>
        <span class="sep">/</span>
        <span>{{ detail.course.title }}</span>
      </nav>

      <section class="course-head">
        <div>
          <div class="cover" :style="coverStyle">
            <img
              v-if="detail.course.cover_url"
              :src="detail.course.cover_url"
              :alt="detail.course.title"
            />
            <b v-else>{{ coverGlyph }}</b>
            <span v-if="!detail.course.cover_url" class="cover-meta">{{ coverMeta }}</span>
          </div>
        </div>
        <div>
          <h2>《{{ detail.course.title }}》</h2>
          <p class="muted" style="margin: 0">
            {{ detail.course.summary || '讲师还没有写简介。' }}
          </p>
          <div class="course-stats">
            <span>教师 <b>{{ detail.course.teacher_name }}</b></span>
            <span><b>{{ detail.course.learner_count }}</b> 人在学</span>
            <span>共 <b>{{ lessonCount }}</b> 节</span>
            <span v-if="previewAvailable">支持试看</span>
          </div>
        </div>
      </section>

      <div class="course-body">
        <div>
          <div class="tabs" role="tablist" aria-label="课程信息">
            <button
              type="button"
              role="tab"
              :class="{ on: activeTab === 'intro' }"
              :aria-selected="activeTab === 'intro'"
              @click="activeTab = 'intro'"
            >
              课程简介
            </button>
            <button
              type="button"
              role="tab"
              :class="{ on: activeTab === 'catalog' }"
              :aria-selected="activeTab === 'catalog'"
              @click="activeTab = 'catalog'"
            >
              目录（{{ lessonCount }}）
            </button>
            <button
              type="button"
              role="tab"
              :class="{ on: activeTab === 'reviews' }"
              :aria-selected="activeTab === 'reviews'"
              @click="activeTab = 'reviews'"
            >
              评价
            </button>
          </div>

          <div v-if="activeTab === 'intro'" class="fade">
            <div v-if="showIntro" class="rich">
              <MarkdownRenderer :html="detail.course.intro_html" />
            </div>
            <div v-else class="empty">
              <span class="serif">讲师还没有写详细介绍</span>
              可以先从目录试看课节
            </div>
          </div>

          <div v-else-if="activeTab === 'catalog'" class="fade">
            <p v-if="chapters.length === 0" class="empty">讲师还没有发布课节。</p>
            <div v-for="(chapter, chapterIndex) in chapters" :key="chapter.id" class="catalog-chapter">
              <h4>
                <span class="no">第 {{ chapterIndex + 1 }} 章</span>
                {{ chapter.title }}
              </h4>
              <div
                v-for="lesson in chapter.lessons"
                :key="lesson.id"
                class="lesson-row"
                :class="{ cur: false }"
              >
                <span class="typechip" :class="typechipClass(lesson.content_type)">
                  {{ kindLabel(lesson.content_type) }}
                </span>
                <div>
                  <div class="l-title">
                    {{ lesson.title }}
                    <span v-if="lesson.is_preview" class="tag tag-trial">试看</span>
                  </div>
                  <div class="l-meta">{{ lessonMeta(lesson) }}</div>
                </div>
                <div>
                  <AccessGate
                    :locked="lesson.locked"
                    :viewer-authorized="detail.course.viewer_authorized"
                    :price-mode="detail.course.price_mode"
                    :course-id="detail.course.id"
                    :lesson-id="lesson.id"
                    :lesson-title="lesson.title"
                    :can-rejoin="detail.course.viewer_can_rejoin"
                    :revoked-reason="detail.course.viewer_revoked_reason"
                    @entitled="onEntitled"
                  >
                    <router-link
                      :to="`/learn/${detail.course.id}/${lesson.id}`"
                      class="btn btn-ghost btn-sm"
                    >
                      打开
                    </router-link>
                  </AccessGate>
                  <span v-if="lesson.locked" class="l-lock" aria-hidden="true">🔒</span>
                </div>
              </div>
            </div>
          </div>

          <div v-else class="fade">
            <ReviewTree :course-id="detail.course.id" :authorized="detail.course.viewer_authorized" />
          </div>
        </div>

        <aside class="panel buy-panel">
          <div class="big-price">
            <span
              v-if="detail.course.price_mode === 'free'"
              class="price-now"
              style="color: var(--moss)"
            >
              免费
            </span>
            <template v-else>
              <span class="price-now">¥ {{ formatPrice(displayPrice) }}</span>
              <span v-if="onSale" class="price-std">¥ {{ formatPrice(detail.course.list_price) }}</span>
            </template>
          </div>
          <p v-if="saleWindowOpen" class="hint">
            限时优惠中，过期恢复 ¥ {{ formatPrice(detail.course.list_price) }}
          </p>
          <p
            v-else-if="!detail.course.viewer_authorized && detail.course.price_mode !== 'free'"
            class="hint"
          >
            首节支持免费试看；购买后解锁全部课节
          </p>

          <div class="hero-actions">
            <button
              v-if="firstLesson && detail.course.viewer_authorized"
              type="button"
              class="btn btn-primary"
              style="width: 100%"
              @click="openFirst"
            >
              继续学习
            </button>
            <button
              v-else-if="firstLesson && detail.course.price_mode === 'free'"
              type="button"
              class="btn btn-primary"
              style="width: 100%"
              :disabled="starting"
              @click="startFree"
            >
              {{ starting ? '授权中…' : detail.course.viewer_can_rejoin ? '再次加入' : '开始学习' }}
            </button>
            <button
              v-else-if="firstLesson"
              type="button"
              class="btn btn-primary"
              style="width: 100%"
              @click="buy"
            >
              立即购买
            </button>
            <span v-else class="hint">这门课还没有可学习的课节。</span>
          </div>
          <p v-if="startError" class="notice error">{{ startError }}</p>

          <ShareBar
            :course-id="detail.course.id"
            :course-title="detail.course.title"
            variant="panel"
          />
        </aside>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { LessonSummaryDTO, PublicCourseDetailDTO } from '@learn-site/contracts';
import { hasTokens } from '@/api/http';
import { fetchCourseDetail, startCourse } from '@/api/learner';
import { loginPathFor } from '@/router/guards';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import { hasRichHtml } from '@/utils/richHtml';
import AccessGate from '@/views/catalog/AccessGate.vue';
import ReviewTree from '@/views/catalog/ReviewTree.vue';
import ShareBar from '@/views/catalog/ShareBar.vue';

defineOptions({ name: 'CourseDetailView' });

type CourseTab = 'intro' | 'catalog' | 'reviews';

const HUES = ['#34566b', '#4c7a5a', '#a8842c', '#6b4a5e', '#3d6b6b', '#5a6470'];

const route = useRoute();
const router = useRouter();
const detail = ref<PublicCourseDetailDTO | null>(null);
const loading = ref(true);
const loadError = ref(false);
const starting = ref(false);
const startError = ref<string | null>(null);
const activeTab = ref<CourseTab>('intro');

const chapters = computed(() => detail.value?.chapters ?? []);
const showIntro = computed(() => hasRichHtml(detail.value?.course.intro_html));
const lessonCount = computed(() =>
  chapters.value.reduce((total, chapter) => total + chapter.lessons.length, 0),
);

const previewAvailable = computed(() =>
  chapters.value.some((chapter) => chapter.lessons.some((lesson) => lesson.is_preview)),
);

const coverStyle = computed(() => ({
  '--hue': HUES[(detail.value?.course.id ?? 0) % HUES.length],
}));

const coverGlyph = computed(() => detail.value?.course.title.slice(0, 1) ?? '课');
const coverMeta = computed(() =>
  (detail.value?.course.title.slice(0, 4) ?? 'COURSE').toUpperCase(),
);

const firstLesson = computed(() => {
  for (const chapter of chapters.value) {
    const lesson = chapter.lessons[0];
    if (lesson && detail.value) return { id: lesson.id, courseId: detail.value.course.id };
  }
  return null;
});

const displayPrice = computed(() => {
  const course = detail.value?.course;
  if (!course) return 0;
  return course.sale_price > 0 ? course.sale_price : course.list_price;
});

const onSale = computed(() => {
  const course = detail.value?.course;
  if (!course || course.price_mode === 'free') return false;
  return course.sale_price > 0 && course.sale_price < course.list_price;
});

const saleWindowOpen = computed(() => {
  const course = detail.value?.course;
  if (!course || course.price_mode !== 'paid' || !(course.sale_price > 0)) return false;
  const start = course.sale_start_at ? Date.parse(course.sale_start_at) : NaN;
  const end = course.sale_end_at ? Date.parse(course.sale_end_at) : NaN;
  if (Number.isNaN(start) || Number.isNaN(end)) return false;
  return Date.now() >= start && Date.now() < end;
});

onMounted(async () => {
  const id = Number(route.params.id);
  if (!Number.isFinite(id) || id <= 0) {
    loadError.value = true;
    loading.value = false;
    return;
  }
  try {
    detail.value = await fetchCourseDetail(id);
  } catch {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
});

function openFirst(): void {
  const target = firstLesson.value;
  if (target) router.push(`/learn/${target.courseId}/${target.id}`);
}

async function startFree(): Promise<void> {
  if (!detail.value) return;
  if (!hasTokens()) {
    await router.push(loginPathFor(route.fullPath));
    return;
  }
  starting.value = true;
  startError.value = null;
  try {
    const result = await startCourse(detail.value.course.id);
    detail.value.course.viewer_authorized = true;
    detail.value.course.viewer_entitlement_status = 'active';
    detail.value.course.viewer_entitlement_source = result.source;
    detail.value.course.viewer_revoked_reason = null;
    detail.value.course.viewer_can_rejoin = false;
    if (result.first_lesson)
      router.push(`/learn/${detail.value.course.id}/${result.first_lesson.id}`);
  } catch (err: unknown) {
    const code = (err as { code?: string }).code;
    if (code === 'UNAUTHENTICATED') {
      await router.push(loginPathFor(route.fullPath));
      return;
    }
    startError.value = code === 'NOT_FOUND' ? '课程不存在或已下架。' : '授权失败，请稍后再试。';
  } finally {
    starting.value = false;
  }
}

function buy(): void {
  if (!detail.value) return;
  router.push(`/checkout/${detail.value.course.id}`);
}

function formatPrice(n: number): string {
  return n % 1 === 0 ? String(n) : n.toFixed(2);
}

function kindLabel(kind: string): string {
  switch (kind) {
    case 'markdown':
      return '图文';
    case 'pdf':
      return 'PDF';
    case 'video':
      return '视频';
    default:
      return kind;
  }
}

function typechipClass(kind: string): string {
  switch (kind) {
    case 'markdown':
      return 't-md';
    case 'pdf':
      return 't-pdf';
    case 'video':
      return 't-video';
    default:
      return '';
  }
}

function lessonMeta(lesson: LessonSummaryDTO): string {
  if (lesson.duration_seconds > 0) {
    const minutes = Math.max(1, Math.round(lesson.duration_seconds / 60));
    return `约 ${minutes} 分钟`;
  }
  return lesson.locked ? '需取得访问权' : '可学习';
}

function onEntitled(): void {
  if (detail.value) {
    detail.value.course.viewer_authorized = true;
    detail.value.course.viewer_entitlement_status = 'active';
    detail.value.course.viewer_revoked_reason = null;
    detail.value.course.viewer_can_rejoin = false;
  }
}
</script>

<style scoped>
.course-detail {
  display: grid;
  gap: 0;
}

.hero-actions {
  display: grid;
  gap: 8px;
}
</style>
