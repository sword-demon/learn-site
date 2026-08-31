<template>
  <main class="page course-detail" :data-course-id="detail?.course.id ?? null">
    <el-skeleton v-if="loading" animated :rows="8" />

    <div v-else-if="loadError" class="course-detail__error">
      <el-alert title="课程暂时读不到，请稍后再试。" type="error" :closable="false" show-icon />
      <el-button data-action="retry" @click="load">重新加载</el-button>
    </div>

    <template v-else-if="detail">
      <nav class="crumbs" aria-label="面包屑">
        <router-link to="/">首页</router-link>
        <span class="sep">/</span>
        <span>{{ detail.course.category_name ?? '课程详情' }}</span>
        <span class="sep">/</span>
        <span>{{ detail.course.title }}</span>
      </nav>

      <div class="course-detail__grid">
        <section class="course-detail__main">
          <header class="course-hero">
            <div class="course-hero__cover" :data-hue="(detail.course.id ?? 0) % MAP_HUES.length">
              <img
                :src="detail.course.cover_url || '/assets/stitch-course-hero.jpg'"
                :alt="detail.course.title"
              />
              <!-- ponytail: legacy used coverStyle CSS var + coverMeta text; now follows MapListView MAP_HUES pattern -->
            </div>
            <div class="course-hero__body">
              <h1 class="course-hero__title">《{{ detail.course.title }}》</h1>
              <p class="course-hero__summary">
                {{ detail.course.summary || '讲师还没有写简介。' }}
              </p>
              <ul class="course-hero__facts">
                <li>
                  <span class="course-hero__fact-label">教师</span>
                  <b>{{ detail.course.teacher_name }}</b>
                  <!-- ponytail: Figma wants teacher_title + teacher_avatar; DTO has only teacher_name -->
                </li>
                <li>
                  <span class="course-hero__fact-label">学员</span>
                  <b>{{ detail.course.learner_count }} 位学员</b>
                  <!-- ponytail: aligned copy with CourseShelfCard / CategoryView -->
                </li>
                <li>
                  <span class="course-hero__fact-label">课时</span>
                  <b>{{ lessonCount }}</b> 节
                  <!-- ponytail: Figma wants lesson_count as separate field; computed from chapters -->
                </li>
                <li v-if="previewAvailable">
                  <span class="course-hero__fact-label">试看</span>
                  <b>支持</b>
                  <!-- ponytail: Figma wants trial_available flag; derive from is_preview lessons -->
                </li>
              </ul>
            </div>
          </header>

          <el-tabs v-model="activeTab" class="course-tabs" aria-label="课程信息">
            <el-tab-pane label="课程简介" name="intro">
              <article class="course-intro">
                <MarkdownRenderer v-if="showIntro" :html="detail.course.intro_html" />
                <el-empty v-else description="讲师还没有写详细介绍，可以先从目录试看课节" />
                <!-- ponytail: Figma wants target_audience + learning_goals as separate blocks; DTO has none -->
              </article>
            </el-tab-pane>

            <el-tab-pane :label="`目录（${lessonCount}）`" name="catalog">
              <ol v-if="chapters.length > 0" class="course-catalog">
                <li
                  v-for="(chapter, chapterIndex) in chapters"
                  :key="chapter.id"
                  class="course-chapter"
                >
                  <header class="course-chapter__head">
                    <span class="course-chapter__index">第 {{ chapterIndex + 1 }} 章</span>
                    <h3 class="course-chapter__title">{{ chapter.title }}</h3>
                  </header>
                  <ul class="course-chapter__lessons">
                    <li
                      v-for="lesson in chapter.lessons"
                      :key="lesson.id"
                      class="course-lesson"
                      :data-locked="!lesson.locked"
                    >
                      <span
                        class="course-lesson__typechip"
                        :class="typechipClass(lesson.content_type)"
                      >
                        {{ kindLabel(lesson.content_type) }}
                      </span>
                      <div class="course-lesson__meta">
                        <span class="course-lesson__title">
                          {{ lesson.title }}
                          <el-tag
                            v-if="lesson.is_preview"
                            type="warning"
                            size="small"
                            effect="plain"
                          >
                            试看
                          </el-tag>
                        </span>
                        <span class="course-lesson__sub">{{ lessonMeta(lesson) }}</span>
                      </div>
                      <div class="course-lesson__action">
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
                          <el-button
                            size="small"
                            data-action="open-lesson"
                            :icon="VideoPlay"
                            @click="openLesson(lesson)"
                          >
                            打开
                          </el-button>
                        </AccessGate>
                        <el-icon
                          v-if="lesson.locked"
                          class="course-lesson__lock"
                          aria-hidden="true"
                        >
                          <Lock />
                        </el-icon>
                      </div>
                    </li>
                  </ul>
                </li>
              </ol>
              <el-empty v-else description="讲师还没有发布课节。" />
            </el-tab-pane>

            <el-tab-pane label="评价" name="reviews">
              <div class="course-reviews">
                <!-- ponytail: Figma wants review_count badge; DTO lacks -->
                <ReviewTree
                  :course-id="detail.course.id"
                  :authorized="detail.course.viewer_authorized"
                />
              </div>
            </el-tab-pane>
          </el-tabs>
        </section>

        <aside class="course-detail__buy-panel buy-panel">
          <div class="buy-panel__price">
            <span
              v-if="detail.course.price_mode === 'free'"
              class="buy-panel__price-now buy-panel__price-free"
            >
              免费
            </span>
            <template v-else>
              <span class="buy-panel__price-now">¥ {{ formatPrice(displayPrice) }}</span>
              <span v-if="onSale" class="buy-panel__price-std">
                ¥ {{ formatPrice(detail.course.list_price) }}
              </span>
            </template>
            <!-- ponytail: Figma wants discount_badge[]; DTO lacks -->
          </div>
          <p v-if="saleWindowOpen" class="buy-panel__hint buy-panel__hint--sale">
            限时优惠中，过期恢复 ¥ {{ formatPrice(detail.course.list_price) }}
          </p>
          <p
            v-else-if="!detail.course.viewer_authorized && detail.course.price_mode !== 'free'"
            class="buy-panel__hint"
          >
            首节支持免费试看；购买后解锁全部课节
          </p>

          <div class="buy-panel__actions">
            <el-button
              v-if="firstLesson && detail.course.viewer_authorized"
              type="primary"
              data-action="continue-course"
              :icon="VideoPlay"
              @click="openFirst"
            >
              继续学习
            </el-button>
            <el-button
              v-else-if="firstLesson && detail.course.price_mode === 'free'"
              type="primary"
              data-action="start-course"
              :icon="ArrowRight"
              :loading="starting"
              @click="startFree"
            >
              {{ detail.course.viewer_can_rejoin ? '再次加入' : '开始学习' }}
            </el-button>
            <el-button
              v-else-if="firstLesson"
              type="primary"
              data-action="buy-course"
              :icon="ShoppingCart"
              @click="buy"
            >
              立即购买
            </el-button>
            <span v-else class="buy-panel__hint">这门课还没有可学习的课节。</span>
          </div>
          <el-alert
            v-if="startError"
            :title="startError"
            type="error"
            :closable="false"
            show-icon
          />

          <ShareBar
            :course-id="detail.course.id"
            :course-title="detail.course.title"
            variant="panel"
          />
          <!-- ponytail: Figma wants handout / community / permanent_access trust badges; DTO has none -->
        </aside>
      </div>
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, Lock, ShoppingCart, VideoPlay } from '@element-plus/icons-vue';
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

const MAP_HUES = ['#5B8FF9', '#5AD8A6', '#F6BD16', '#E86452', '#6DC8EC', '#945FB9'] as const;

const route = useRoute();
const router = useRouter();
const detail = ref<PublicCourseDetailDTO | null>(null);
const loading = ref(true);
const loadError = ref(false);
const starting = ref(false);
const startError = ref<string | null>(null);
const activeTab = ref<CourseTab>('intro');

// ponytail: H1 route-param guard via computed + Number.isFinite check inside load()
const id = computed(() => Number(route.params.id));

const chapters = computed(() => detail.value?.chapters ?? []);
const showIntro = computed(() => hasRichHtml(detail.value?.course.intro_html));
const lessonCount = computed(() =>
  chapters.value.reduce((total, chapter) => total + chapter.lessons.length, 0),
);

const previewAvailable = computed(() =>
  chapters.value.some((chapter) => chapter.lessons.some((lesson) => lesson.is_preview)),
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

async function load(): Promise<void> {
  if (!Number.isFinite(id.value) || id.value <= 0) {
    loadError.value = true;
    loading.value = false;
    return;
  }
  loading.value = true;
  loadError.value = false;
  try {
    detail.value = await fetchCourseDetail(id.value);
    unlockCatalogLessons();
  } catch {
    loadError.value = true;
    detail.value = null;
  } finally {
    loading.value = false;
  }
}

// ponytail: matches MapDetailView pattern — SPA navigation re-loads when id changes
watch(
  id,
  () => {
    void load();
  },
  { immediate: true },
);

function unlockCatalogLessons(): void {
  if (!detail.value?.course.viewer_authorized) return;
  for (const chapter of detail.value.chapters) {
    for (const lesson of chapter.lessons) {
      lesson.locked = false;
    }
  }
}

// ponytail: previously duplicated across startFree() and onEntitled() (5 flags + unlock)
type EntitlementSource = 'free' | 'purchase';

function applyEntitlement(source?: EntitlementSource): void {
  if (!detail.value) return;
  detail.value.course.viewer_authorized = true;
  detail.value.course.viewer_entitlement_status = 'active';
  if (source !== undefined) {
    detail.value.course.viewer_entitlement_source = source;
  }
  detail.value.course.viewer_revoked_reason = null;
  detail.value.course.viewer_can_rejoin = false;
  unlockCatalogLessons();
}

async function openLesson(lesson: LessonSummaryDTO): Promise<void> {
  if (!detail.value || lesson.locked) return;
  const target = `/learn/${detail.value.course.id}/${lesson.id}`;
  if (!hasTokens()) {
    await router.push(loginPathFor(target));
    return;
  }
  await router.push(target);
}

function openFirst(): void {
  const target = firstLesson.value;
  if (target) void router.push(`/learn/${target.courseId}/${target.id}`);
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
    applyEntitlement(result.source);
    if (result.first_lesson)
      await router.push(`/learn/${detail.value.course.id}/${result.first_lesson.id}`);
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
  applyEntitlement();
}
</script>

<style scoped>
.course-detail {
  padding-bottom: 48px;
}

.course-detail > .crumbs {
  display: none;
}

.course-detail__error {
  display: flex;
  flex-direction: column;
  gap: 12px;
  align-items: flex-start;
  margin-top: 16px;
}

.crumbs {
  display: flex;
  gap: 8px;
  align-items: center;
  font-size: 13px;
  color: var(--ink-2, #606266);
  margin-bottom: 16px;
}

.crumbs a {
  color: var(--ink-2, #606266);
  text-decoration: none;
}

.crumbs a:hover {
  color: var(--seal, #409eff);
}

.crumbs .sep {
  color: var(--ink-2, #c0c4cc);
}

.course-detail__grid {
  display: grid;
  grid-template-columns: minmax(0, 2fr) minmax(280px, 1fr);
  gap: 24px;
  align-items: start;
}

.course-detail__main {
  min-width: 0;
}

.course-hero {
  display: grid;
  gap: 10px;
}

.course-hero__cover {
  width: 100%;
  aspect-ratio: 16 / 9;
  height: auto;
  border-radius: 12px;
  overflow: hidden;
  background: var(--card-2, #f5f7fa);
  display: flex;
  align-items: center;
  justify-content: center;
  border: 1px solid var(--line-2);
}

.course-hero__cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.course-hero__cover-glyph {
  font-size: 64px;
  font-weight: 700;
  color: #fff;
}

.course-hero__cover[data-hue='0'] {
  background: #5b8ff9;
}
.course-hero__cover[data-hue='1'] {
  background: #5ad8a6;
}
.course-hero__cover[data-hue='2'] {
  background: #f6bd16;
}
.course-hero__cover[data-hue='3'] {
  background: #e86452;
}
.course-hero__cover[data-hue='4'] {
  background: #6dc8ec;
}
.course-hero__cover[data-hue='5'] {
  background: #945fb9;
}

.course-hero__body {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 12px;
  padding: 24px;
  border: 1px solid var(--line, #ebeef5);
  border-radius: 12px;
  background: #fff;
  box-shadow: var(--shadow);
}

.course-hero__title {
  margin: 0;
  font-family: var(--serif);
  font-size: 32px;
  font-weight: 600;
  color: var(--ink, #303133);
}

.course-hero__summary {
  margin: 0;
  font-size: 14px;
  color: var(--ink-2, #606266);
  line-height: 1.6;
}

.course-hero__facts {
  list-style: none;
  margin: 8px 0 0;
  padding: 0;
  display: flex;
  flex-wrap: wrap;
  gap: 12px 24px;
  font-size: 13px;
  color: var(--ink-2, #606266);
}

.course-hero__facts li {
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.course-hero__facts b {
  color: var(--ink, #303133);
  font-weight: 600;
}

.course-hero__fact-label {
  color: var(--ink-2, #909399);
}

.course-tabs {
  margin-top: 10px;
  padding: 0 16px 16px;
  border: 1px solid var(--line, #ebeef5);
  border-radius: 12px;
  background: #fff;
  box-shadow: var(--shadow);
}

.course-tabs :deep(.el-tabs__header) {
  margin: 0 0 16px;
}

.course-tabs :deep(.el-tabs__item) {
  color: var(--ink-2, #606266);
  font-family: var(--serif);
  font-size: 15px;
}

.course-tabs :deep(.el-tabs__item.is-active) {
  color: var(--seal, #409eff);
  font-weight: 700;
}

.course-catalog {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 24px;
}

.course-chapter {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.course-chapter__head {
  display: flex;
  align-items: baseline;
  gap: 8px;
  padding-bottom: 8px;
  border-bottom: 1px dashed var(--line, #ebeef5);
}

.course-chapter__index {
  font-size: 12px;
  color: var(--ink-2, #c0c4cc);
  letter-spacing: 0.5px;
}

.course-chapter__title {
  margin: 0;
  font-size: 15px;
  color: var(--ink, #303133);
}

.course-chapter__lessons {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.course-lesson {
  display: grid;
  grid-template-columns: auto 1fr auto;
  gap: 12px;
  align-items: center;
  padding: 10px 12px;
  border: 1px solid var(--line, #ebeef5);
  border-radius: var(--r, 6px);
  background: #fff;
}

.course-lesson[data-locked='false'] {
  background: var(--success-soft, #f0f9eb);
}

.course-lesson__typechip {
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 4px;
  color: #fff;
  background: var(--ink-2, #909399);
}

.course-lesson__typechip.t-md {
  background: var(--seal, #409eff);
}
.course-lesson__typechip.t-pdf {
  background: #c45656;
}
.course-lesson__typechip.t-video {
  background: var(--moss, #67c23a);
}

.course-lesson__meta {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.course-lesson__title {
  font-size: 14px;
  color: var(--ink, #303133);
  display: flex;
  align-items: center;
  gap: 8px;
}

.course-lesson__sub {
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.course-lesson__action {
  display: flex;
  align-items: center;
  gap: 8px;
}

.course-lesson__lock {
  color: var(--ink-2, #c0c4cc);
}

.course-reviews {
  padding: 8px 0;
}

.course-detail__buy-panel.buy-panel {
  position: sticky;
  top: 80px;
  padding: 24px;
  border: 1px solid var(--line, #ebeef5);
  border-radius: 12px;
  background: #fff;
  display: flex;
  flex-direction: column;
  gap: 14px;
  box-shadow: var(--shadow);
}

.buy-panel__price {
  display: flex;
  align-items: baseline;
  gap: 8px;
}

.buy-panel__price-now {
  font-family: var(--serif);
  font-size: 40px;
  font-weight: 700;
  color: var(--seal, #409eff);
}

.buy-panel__price-free {
  color: var(--moss, #67c23a);
}

.buy-panel__price-std {
  font-size: 14px;
  color: var(--ink-2, #909399);
  text-decoration: line-through;
}

.buy-panel__hint {
  margin: 0;
  font-size: 12px;
  color: var(--ink-2, #909399);
}

.buy-panel__hint--sale {
  color: var(--moss, #67c23a);
}

.buy-panel__actions {
  display: grid;
  gap: 8px;
}

.buy-panel__actions > .el-button {
  width: 100%;
  margin-left: 0;
}

@media (max-width: 900px) {
  .course-detail__grid {
    grid-template-columns: 1fr;
    gap: 16px;
  }

  .course-detail__buy-panel.buy-panel {
    position: static;
  }
}

@media (max-width: 560px) {
  .course-hero__cover {
    height: 140px;
  }

  .course-hero__title {
    font-size: 21px;
  }

  .course-tabs {
    padding-inline: 12px;
  }
}
</style>
