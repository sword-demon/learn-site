<template>
  <main class="page catalog-archive">
    <header class="archive-head">
      <div>
        <p class="badge">
          <router-link to="/">首页</router-link>
          <span aria-hidden="true"> / </span>
          <span>{{ category?.name ?? '分类' }}</span>
        </p>
        <h1 class="display">{{ category?.name ?? '分类课程' }}</h1>
        <p class="lede">{{ lede }}</p>
      </div>
      <p v-if="!loading && !loadError" class="archive-count">
        <strong>{{ total }}</strong> 门课程
      </p>
    </header>

    <el-skeleton v-if="loading" animated :rows="5" />
    <el-alert
      v-else-if="loadError"
      title="分类暂时读不到，请稍后再试。"
      type="error"
      :closable="false"
      show-icon
    />
    <el-empty v-else-if="items.length === 0" description="此分类下还没有公开课程。" />
    <ul v-else class="course-grid">
      <li v-for="(course, index) in items" :key="course.id" class="course-card">
        <router-link :to="`/courses/${course.id}`" class="cover">
          <span class="course-index latin">{{
            String(index + 1 + (page - 1) * limit).padStart(2, '0')
          }}</span>
          <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
          <span v-else class="cover-fallback display" aria-hidden="true">{{
            course.title.slice(0, 2)
          }}</span>
        </router-link>
        <div class="body">
          <h2 class="title">
            <router-link :to="`/courses/${course.id}`">{{ course.title }}</router-link>
          </h2>
          <p class="teacher">讲师 · {{ course.teacher_name }}</p>
          <p class="summary">{{ course.summary || '讲师还没有写简介。' }}</p>
          <p class="meta">
            <el-tag v-if="course.price_mode === 'free'" type="success" size="small">免费</el-tag>
            <template v-else>
              <span class="price-now"
                >¥ {{ formatPrice(course.sale_price || course.list_price) }}</span
              >
              <span v-if="course.sale_price > 0" class="price-was"
                >¥ {{ formatPrice(course.list_price) }}</span
              >
            </template>
            <el-tag v-if="course.preview_available" type="warning" size="small" effect="plain"
              >支持试看</el-tag
            >
            <span class="learners">{{ course.learner_count }} 位学员</span>
          </p>
        </div>
      </li>
    </ul>

    <el-pagination
      v-if="totalPages > 1"
      v-model:page-size="limit"
      class="pager"
      :current-page="page"
      :page-sizes="[10, 20, 50]"
      :total="total"
      layout="total, sizes, prev, pager, next"
      aria-label="分页"
      @current-change="goto"
      @size-change="onSizeChange"
    />
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import type { CategoryBreadcrumbDTO, CourseListItemDTO } from '@learn-site/contracts';
import { fetchCategoryCourses } from '@/api/learner';

defineOptions({ name: 'CategoryView' });

const route = useRoute();
const router = useRouter();
const category = ref<CategoryBreadcrumbDTO | null>(null);
const items = ref<CourseListItemDTO[]>([]);
const total = ref(0);
const page = ref(1);
const limit = ref(20);
const loading = ref(true);
const loadError = ref(false);

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit.value)));

const lede = computed(() => {
  if (loading.value) return '正在铺开课程…';
  if (loadError.value) return '分类暂时读不到，课室还在。';
  if (total.value === 0) return '还没有发布中的课程，管理员发布后会出现在这里。';
  return `共 ${total.value} 门已发布课程，默认按更新时间倒序。`;
});

function formatPrice(n: number): string {
  return n.toFixed(2);
}

function goto(next: number): void {
  if (next === page.value) return;
  router.replace({ query: { ...route.query, page: String(next) } });
}

function onSizeChange(): void {
  goto(1);
}

async function load(): Promise<void> {
  const id = Number(route.params.id);
  if (!Number.isFinite(id) || id <= 0) {
    loadError.value = true;
    loading.value = false;
    return;
  }
  const queryPage = Number(route.query.page);
  page.value = Number.isFinite(queryPage) && queryPage > 0 ? queryPage : 1;
  loading.value = true;
  loadError.value = false;
  try {
    const { category: cat, list } = await fetchCategoryCourses(id, page.value, limit.value);
    category.value = cat;
    items.value = list.items;
    total.value = list.total;
  } catch {
    loadError.value = true;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(() => [route.params.id, route.query.page], load);
</script>

<style scoped>
.catalog-archive {
  display: grid;
  gap: 28px;
}

.archive-head {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 24px;
  padding-bottom: 25px;
  border-bottom: 1px solid var(--line);
}

.archive-head .badge {
  margin-bottom: 17px;
}

.archive-head .display {
  margin: 0 0 9px;
  color: var(--pine-deep);
  font-size: 2.8rem;
  line-height: 1.12;
}

.archive-count {
  flex-shrink: 0;
  margin: 0 0 4px;
  color: var(--muted);
  font-size: 0.8rem;
}

.archive-count strong {
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 1.5rem;
}

.course-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
  gap: 18px;
  margin: 0;
  padding: 0;
  list-style: none;
}

.course-card {
  display: flex;
  flex-direction: column;
  overflow: hidden;
  border: 1px solid var(--line);
  border-radius: 7px;
  background: var(--surface);
  box-shadow: 0 10px 26px rgba(31, 60, 48, 0.07);
  transition:
    transform 0.24s ease,
    box-shadow 0.24s ease;
}

.course-card:hover {
  transform: translateY(-4px);
  box-shadow: 0 17px 34px rgba(31, 60, 48, 0.12);
}

.cover {
  position: relative;
  display: block;
  aspect-ratio: 16 / 10;
  overflow: hidden;
  background: var(--paper-deep);
}

.cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.4s ease;
}

.course-card:hover .cover img {
  transform: scale(1.035);
}

.course-index {
  position: absolute;
  top: 11px;
  left: 12px;
  z-index: 1;
  color: #fffefa;
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.12em;
  text-shadow: 0 1px 3px rgba(0, 0, 0, 0.42);
}

.cover-fallback {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  color: var(--pine);
  font-size: 2rem;
}

.body {
  display: grid;
  gap: 7px;
  padding: 15px 16px 16px;
}

.title {
  margin: 0;
  font-size: 1.02rem;
  line-height: 1.4;
}

.title a {
  color: var(--ink);
  text-decoration: none;
}

.title a:hover {
  color: var(--accent);
}

.teacher,
.summary {
  margin: 0;
  color: var(--muted);
  font-size: 0.78rem;
}

.summary {
  display: -webkit-box;
  min-height: 2.5em;
  overflow: hidden;
  line-height: 1.55;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.meta {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 4px 0 0;
  font-size: 0.77rem;
}

.price-now {
  color: var(--ink);
  font-family: var(--font-mono);
  font-weight: 700;
}

.price-was {
  color: var(--muted);
  font-family: var(--font-mono);
  text-decoration: line-through;
}

.learners {
  margin-left: auto;
  color: var(--muted);
}

.pager {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 14px;
}

.page-indicator {
  color: var(--muted);
  font-size: 0.8rem;
}

@media (max-width: 560px) {
  .archive-head .display {
    font-size: 2.25rem;
  }

  .archive-head {
    display: grid;
    align-items: start;
    gap: 8px;
  }

  .archive-count {
    margin: 0;
  }
}
</style>
