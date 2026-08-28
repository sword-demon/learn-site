<template>
  <main class="page course-detail">
    <p class="badge">
      <router-link to="/">首页</router-link>
      <span aria-hidden="true"> / </span>
      <span>{{ detail?.course.category_name ?? '课程详情' }}</span>
    </p>

    <p v-if="loading" class="notice">课程加载中…</p>
    <p v-else-if="loadError" class="notice error">课程暂时读不到，请稍后再试。</p>
    <template v-else-if="detail">
      <section class="course-overview">
        <div class="course-copy">
          <p class="eyebrow">
            <span class="eyebrow-rule" />课程档案 · {{ detail.course.category_name ?? '公开课程' }}
          </p>
          <h1 class="display">{{ detail.course.title }}</h1>
          <p class="course-teacher">讲师 · {{ detail.course.teacher_name }}</p>
          <p class="lede course-summary">{{ detail.course.summary || '讲师还没有写简介。' }}</p>
          <div class="course-facts">
            <span>{{ detail.course.learner_count }} 位学员</span>
            <span>{{ chapters.length }} 个章节</span>
            <span
              v-if="chapters.some((chapter) => chapter.lessons.some((lesson) => lesson.is_preview))"
            >支持试看</span>
          </div>
          <div class="course-price">
            <span v-if="detail.course.price_mode === 'free'" class="tag free">免费课程</span>
            <template v-else>
              <span class="price-now">¥ {{ formatPrice(detail.course.sale_price || detail.course.list_price) }}</span>
              <span v-if="detail.course.sale_price > 0" class="price-was">¥ {{ formatPrice(detail.course.list_price) }}</span>
              <span v-if="saleWindowOpen" class="tag sale">限时优惠</span>
            </template>
          </div>
          <div class="hero-actions">
            <button
              v-if="firstLesson && detail.course.viewer_authorized"
              type="button"
              class="btn btn-primary"
              @click="openFirst"
            >
              继续学习
            </button>
            <button
              v-else-if="firstLesson && detail.course.price_mode === 'free'"
              type="button"
              class="btn btn-primary"
              :disabled="starting"
              @click="startFree"
            >
              {{ starting ? '授权中…' : '开始学习' }}
            </button>
            <button
              v-else-if="firstLesson"
              type="button"
              class="btn btn-primary"
              :disabled="buying"
              @click="buy"
            >
              {{ buying ? '下单中…' : '立即购买' }}
            </button>
            <span v-else class="notice">这门课还没有可学习的课节。</span>
          </div>
        </div>
        <div class="hero-cover">
          <img
            v-if="detail.course.cover_url"
            :src="detail.course.cover_url"
            :alt="detail.course.title"
          />
          <span v-else class="cover-fallback display">{{ detail.course.title.slice(0, 2) }}</span>
        </div>
      </section>

      <section v-if="detail.course.intro_html" class="section-frame intro">
        <p class="eyebrow"><span class="eyebrow-rule" />课程说明</p>
        <h2 class="section-title display">课程介绍</h2>
        <div class="prose" v-html="detail.course.intro_html" />
      </section>

      <section class="section-frame lessons">
        <header class="section-heading">
          <div>
            <p class="eyebrow"><span class="eyebrow-rule" />课程目录</p>
            <h2 class="section-title display">按章节展开学习</h2>
          </div>
          <span class="lesson-count">{{ chapters.length }} 个章节</span>
        </header>
        <p v-if="chapters.length === 0" class="empty">讲师还没有发布课节。</p>
        <ol v-else class="chapter-list">
          <li v-for="chapter in chapters" :key="chapter.id" class="chapter">
            <div class="chapter-heading">
              <span class="chapter-number latin">{{
                String(chapter.sort + 1).padStart(2, '0')
              }}</span>
              <div>
                <h3 class="chapter-title">{{ chapter.title }}</h3>
                <p class="chapter-meta">{{ chapter.lessons.length }} 个课节</p>
              </div>
            </div>
            <ol class="lesson-list">
              <li
                v-for="lesson in chapter.lessons"
                :key="lesson.id"
                class="lesson-row"
                :class="{
                  'is-locked': lesson.locked,
                  'is-preview': lesson.is_preview,
                }"
              >
                <div class="lesson-main">
                  <span class="lesson-index latin">{{
                    String(lesson.sort + 1).padStart(2, '0')
                  }}</span>
                  <div class="lesson-copy">
                    <p class="lesson-name">
                      {{ lesson.title }}
                      <span v-if="lesson.is_preview" class="tag preview">试看</span>
                    </p>
                    <p class="lesson-meta">
                      <span class="kind">{{ kindLabel(lesson.content_type) }}</span>
                      <span v-if="lesson.locked" class="lock-hint">需登录或取得访问权</span>
                    </p>
                  </div>
                </div>
                <div class="lesson-action">
                  <AccessGate
                    :locked="lesson.locked"
                    :viewer-authorized="detail.course.viewer_authorized"
                    :price-mode="detail.course.price_mode"
                    :course-id="detail.course.id"
                    :lesson-id="lesson.id"
                    :lesson-title="lesson.title"
                    @entitled="onEntitled"
                  >
                    <router-link
                      :to="`/learn/${detail.course.id}/${lesson.id}`"
                      class="btn btn-link lesson-open"
                    >
                      打开课节 <span aria-hidden="true">→</span>
                    </router-link>
                  </AccessGate>
                </div>
              </li>
            </ol>
          </li>
        </ol>
      </section>

      <section class="section-frame feedback-frame">
        <ReviewTree :course-id="detail.course.id" :authorized="detail.course.viewer_authorized" />
      </section>

      <ShareBar :course-id="detail.course.id" :course-title="detail.course.title" />
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { PublicCourseDetailDTO } from '@learn-site/contracts';
import { createCourseOrder, fetchCourseDetail, startCourse } from '@/api/learner';
import AccessGate from '@/views/catalog/AccessGate.vue';
import ReviewTree from '@/views/catalog/ReviewTree.vue';
import ShareBar from '@/views/catalog/ShareBar.vue';

defineOptions({ name: 'CourseDetailView' });

const route = useRoute();
const router = useRouter();
const detail = ref<PublicCourseDetailDTO | null>(null);
const loading = ref(true);
const loadError = ref(false);
const starting = ref(false);
const buying = ref(false);
const chapters = computed(() => detail.value?.chapters ?? []);

const firstLesson = computed(() => {
  for (const chapter of chapters.value) {
    const lesson = chapter.lessons[0];
    if (lesson && detail.value) return { id: lesson.id, courseId: detail.value.course.id };
  }
  return null;
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
  starting.value = true;
  try {
    const result = await startCourse(detail.value.course.id);
    detail.value.course.viewer_authorized = true;
    if (result.first_lesson)
      router.push(`/learn/${detail.value.course.id}/${result.first_lesson.id}`);
  } catch {
    loadError.value = true;
  } finally {
    starting.value = false;
  }
}

async function buy(): Promise<void> {
  if (!detail.value) return;
  buying.value = true;
  try {
    await createCourseOrder(detail.value.course.id);
    router.push('/me/orders');
  } catch {
    loadError.value = true;
  } finally {
    buying.value = false;
  }
}

function formatPrice(n: number): string {
  return n.toFixed(2);
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

function onEntitled(): void {
  if (detail.value) detail.value.course.viewer_authorized = true;
}
</script>

<style scoped>
.course-detail {
  display: grid;
  gap: 28px;
}

.course-detail > .badge {
  margin-bottom: -9px;
}

.course-overview {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(360px, 0.85fr);
  gap: 54px;
  align-items: center;
  padding: 26px 0 42px;
  border-bottom: 1px solid var(--line);
}

.course-copy {
  min-width: 0;
}

.course-copy .display {
  max-width: 15ch;
  margin: 0 0 10px;
  color: var(--pine-deep);
  font-size: 3rem;
  line-height: 1.16;
}

.course-teacher,
.course-summary {
  margin: 0;
}

.course-teacher {
  color: var(--muted);
  font-size: 0.83rem;
}

.course-summary {
  max-width: 52ch;
  margin-top: 17px;
}

.course-facts,
.course-price,
.hero-actions {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 10px;
}

.course-facts {
  margin-top: 21px;
  color: var(--muted);
  font-size: 0.78rem;
}

.course-facts span + span::before {
  margin-right: 10px;
  color: var(--line);
  content: '/';
}

.course-price {
  margin-top: 22px;
}

.price-now {
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 1.45rem;
  font-weight: 700;
}

.price-was {
  color: var(--muted);
  font-family: var(--font-mono);
  font-size: 0.78rem;
  text-decoration: line-through;
}

.course-copy .hero-actions {
  margin-top: 22px;
}

.hero-cover {
  aspect-ratio: 1.35;
  overflow: hidden;
  border: 1px solid var(--line);
  background: var(--paper-deep);
  box-shadow:
    14px 14px 0 var(--paper-deep),
    var(--shadow);
  transform: rotate(1deg);
}

.hero-cover img,
.cover-fallback {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.cover-fallback {
  display: flex;
  align-items: center;
  justify-content: center;
  color: var(--pine);
  font-size: 3rem;
}

.section-frame {
  padding: 24px 26px 28px;
  border-top: 3px solid var(--pine);
  border-bottom: 1px solid var(--line);
  background: rgba(255, 254, 250, 0.66);
}

.section-title {
  margin: 5px 0 14px;
  color: var(--pine-deep);
  font-size: 1.55rem;
}

.intro .prose {
  max-width: 72ch;
}

.section-heading {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 16px;
}

.lesson-count {
  color: var(--muted);
  font-size: 0.8rem;
}

.chapter-list {
  display: grid;
  gap: 15px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.chapter {
  padding: 17px 0 0;
  border-top: 1px solid var(--line);
}

.chapter-heading {
  display: flex;
  gap: 13px;
  align-items: start;
  margin-bottom: 9px;
}

.chapter-number {
  padding-top: 2px;
  color: var(--accent);
  font-size: 0.75rem;
  font-weight: 700;
}

.chapter-title {
  margin: 0;
  color: var(--pine-deep);
  font-size: 1rem;
}

.chapter-meta {
  margin: 3px 0 0;
  color: var(--muted);
  font-size: 0.74rem;
}

.lesson-list {
  display: grid;
  gap: 6px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.lesson-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 12px;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid transparent;
  border-radius: 8px;
  transition:
    border-color 0.2s ease,
    background-color 0.2s ease;
}

.lesson-row:hover {
  border-color: var(--line);
  background: rgba(238, 241, 234, 0.55);
}

.lesson-row.is-locked {
  background: rgba(255, 254, 250, 0.5);
}

.lesson-row.is-locked:hover {
  border-color: rgba(201, 94, 67, 0.35);
  background: var(--accent-soft);
}

.lesson-main {
  display: flex;
  gap: 12px;
  align-items: flex-start;
  min-width: 0;
}

.lesson-index {
  flex-shrink: 0;
  padding-top: 2px;
  color: var(--accent);
  font-size: 0.72rem;
  font-weight: 700;
}

.lesson-copy {
  min-width: 0;
}

.lesson-name {
  margin: 0;
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  color: var(--pine-deep);
  font-size: 0.9rem;
  font-weight: 600;
  line-height: 1.4;
}

.lesson-row.is-locked .lesson-name {
  color: var(--ink-soft);
}

.lesson-meta {
  margin: 4px 0 0;
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  align-items: center;
  color: var(--muted);
  font-size: 0.74rem;
}

.kind {
  padding-right: 8px;
  border-right: 1px solid var(--line);
}

.lock-hint {
  color: var(--accent-deep);
  font-size: 0.72rem;
}

.lesson-action {
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: flex-end;
}

.lesson-open {
  white-space: nowrap;
}

.feedback-frame {
  padding-bottom: 20px;
}

@media (max-width: 820px) {
  .course-overview {
    grid-template-columns: 1fr;
    gap: 34px;
  }

  .hero-cover {
    max-width: 520px;
  }
}

@media (max-width: 560px) {
  .course-copy .display {
    font-size: 2.35rem;
  }

  .section-frame {
    padding: 20px 16px 22px;
  }

  .section-heading {
    align-items: start;
    flex-direction: column;
    gap: 2px;
  }

  .lesson-row {
    grid-template-columns: 1fr;
    gap: 10px;
  }

  .lesson-action {
    justify-content: flex-start;
  }
}
</style>
