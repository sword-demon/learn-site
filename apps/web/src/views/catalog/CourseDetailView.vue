<template>
  <main class="page course-detail">
    <p class="badge">
      <router-link to="/">首页</router-link>
      <span aria-hidden="true"> / </span>
      <span>{{ detail?.course.category_name ?? '分类' }}</span>
    </p>

    <p v-if="loading" class="notice">课程加载中…</p>
    <p v-else-if="loadError" class="notice error">课程暂时读不到, 请稍后再试.</p>
    <template v-else-if="detail">
      <section class="hero">
        <div class="hero-text">
          <h1 class="display">{{ detail.course.title }}</h1>
          <p class="lede teacher">讲师 · {{ detail.course.teacher_name }}</p>
          <p class="lede summary">{{ detail.course.summary || '讲师还没有写简介.' }}</p>
          <p class="price">
            <span v-if="detail.course.price_mode === 'free'" class="tag free">免费</span>
            <template v-else>
              <span class="price-now">¥ {{ formatPrice(detail.course.sale_price || detail.course.list_price) }}</span>
              <span v-if="detail.course.sale_price > 0" class="price-was">¥ {{ formatPrice(detail.course.list_price) }}</span>
              <span v-if="saleWindowOpen" class="tag sale">限时优惠</span>
            </template>
            <span class="learners">已有 {{ detail.course.learner_count }} 位学员</span>
          </p>
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
            <span v-else class="notice">这门课还没有可学习的课节.</span>
          </div>
        </div>
        <div v-if="detail.course.cover_url" class="hero-cover">
          <img :src="detail.course.cover_url" :alt="detail.course.title" />
        </div>
      </section>

      <section v-if="detail.course.intro_html" class="intro">
        <h2 class="section-title">课程介绍</h2>
        <!-- server is the sanitizer's single source of truth (FR-009) -->
        <div class="prose" v-html="detail.course.intro_html" />
      </section>

      <section class="lessons">
        <h2 class="section-title">课程目录</h2>
        <p v-if="chapters.length === 0" class="empty">讲师还没有发布课节.</p>
        <ol v-else class="chapter-list">
          <li v-for="chapter in chapters" :key="chapter.id" class="chapter">
            <h3 class="chapter-title">{{ chapter.sort + 1 }}. {{ chapter.title }}</h3>
            <ol class="lesson-list">
              <li
                v-for="lesson in chapter.lessons"
                :key="lesson.id"
                class="lesson-row"
                :data-locked="lesson.locked"
              >
                <span class="lesson-title">
                  {{ lesson.sort + 1 }}. {{ lesson.title }}
                  <span v-if="lesson.is_preview" class="tag preview">试看</span>
                  <span class="kind">{{ kindLabel(lesson.content_type) }}</span>
                </span>
                <AccessGate
                  :locked="lesson.locked"
                  :viewer-authorized="detail.course.viewer_authorized"
                  :price-mode="detail.course.price_mode"
                  :course-id="detail.course.id"
                  :lesson-title="lesson.title"
                >
                  <router-link :to="`/learn/${detail.course.id}/${lesson.id}`" class="btn btn-link">
                    打开
                  </router-link>
                </AccessGate>
              </li>
            </ol>
          </li>
        </ol>
      </section>

      <ReviewTree
        :course-id="detail?.course.id ?? 0"
        :authorized="detail.course.viewer_authorized"
      />

      <ShareBar :course-id="detail?.course.id ?? 0" :course-title="detail?.course.title ?? ''" />
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
  for (const ch of chapters.value) {
    for (const ls of ch.lessons) {
      return { id: ls.id, courseId: detail.value!.course.id };
    }
  }
  return null;
});

const saleWindowOpen = computed(() => {
  const c = detail.value?.course;
  if (!c || c.price_mode !== 'paid') return false;
  if (!(c.sale_price > 0)) return false;
  const now = Date.now();
  const start = c.sale_start_at ? Date.parse(c.sale_start_at) : NaN;
  const end = c.sale_end_at ? Date.parse(c.sale_end_at) : NaN;
  if (Number.isNaN(start) || Number.isNaN(end)) return false;
  return now >= start && now < end;
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
  if (!target) return;
  router.push(`/learn/${target.courseId}/${target.id}`);
}

async function startFree(): Promise<void> {
  if (!detail.value) return;
  starting.value = true;
  try {
    const result = await startCourse(detail.value.course.id);
    detail.value.course.viewer_authorized = true;
    if (result.first_lesson) {
      router.push(`/learn/${detail.value.course.id}/${result.first_lesson.id}`);
    }
  } catch {
    /* surfaced inline via loadError path */
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
    /* surfaced via orders page */
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
</script>

<style scoped>
.course-detail {
  display: grid;
  gap: 28px;
}
.hero {
  display: grid;
  gap: 18px;
  grid-template-columns: 1fr;
}
@media (min-width: 768px) {
  .hero {
    grid-template-columns: 2fr 1fr;
    align-items: start;
  }
}
.hero-text .display {
  margin: 0 0 4px 0;
}
.teacher {
  color: var(--color-text-muted, #5b6472);
  margin: 0 0 6px 0;
}
.summary {
  margin: 0 0 12px 0;
}
.price {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin: 0 0 16px 0;
}
.price-now {
  font-size: 22px;
  font-weight: 600;
}
.price-was {
  color: var(--color-text-muted, #5b6472);
  text-decoration: line-through;
}
.learners {
  color: var(--color-text-muted, #5b6472);
  font-size: 13px;
}
.tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  background: var(--color-bg-soft, #eef1f7);
}
.tag.free {
  background: #e7f6ec;
  color: #137a3c;
}
.tag.sale {
  background: #fff1d6;
  color: #8a5a00;
}
.tag.preview {
  background: #e6efff;
  color: #1e3a8a;
  margin-left: 8px;
}
.kind {
  color: var(--color-text-muted, #5b6472);
  margin-left: 8px;
  font-size: 12px;
}
.hero-cover img {
  width: 100%;
  max-height: 240px;
  object-fit: cover;
  border-radius: 12px;
}
.section-title {
  margin: 0 0 12px 0;
}
.intro .prose {
  line-height: 1.7;
}
.chapter-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 16px;
}
.chapter {
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 10px;
  padding: 14px 16px;
}
.chapter-title {
  margin: 0 0 8px 0;
}
.lesson-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 6px;
}
.lesson-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  padding: 6px 8px;
  border-radius: 6px;
}
.lesson-row[data-locked='true'] {
  background: var(--color-bg-soft, #f7f8fb);
}
.lesson-title {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.btn {
  display: inline-flex;
  align-items: center;
  padding: 6px 12px;
  border-radius: 6px;
  border: 1px solid transparent;
  font: inherit;
  cursor: pointer;
  text-decoration: none;
}
.btn-primary {
  background: var(--color-primary, #2563eb);
  color: #fff;
}
.btn-link {
  color: var(--color-primary, #2563eb);
  padding: 4px 8px;
  border-radius: 4px;
}
.empty,
.notice {
  color: var(--color-text-muted, #5b6472);
}
.notice.error {
  color: #b42318;
}
.hero-actions {
  display: flex;
  gap: 12px;
  align-items: center;
}
.badge a {
  color: inherit;
  text-decoration: none;
}
</style>
