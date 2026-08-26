<template>
  <main class="page">
    <p class="badge">
      <router-link to="/">首页</router-link>
      <span aria-hidden="true"> / </span>
      <span>{{ category?.name ?? '分类' }}</span>
    </p>
    <h1 class="display">{{ category?.name ?? '分类课程' }}</h1>
    <p class="lede">{{ lede }}</p>

    <p v-if="loading" class="notice">课程加载中…</p>
    <p v-else-if="loadError" class="notice error">分类暂时读不到, 请稍后再试.</p>
    <p v-else-if="items.length === 0" class="empty">此分类下还没有公开课程.</p>
    <ul v-else class="course-grid">
      <li v-for="course in items" :key="course.id" class="course-card">
        <router-link :to="`/courses/${course.id}`" class="cover">
          <img v-if="course.cover_url" :src="course.cover_url" :alt="course.title" />
          <span v-else class="cover-fallback" aria-hidden="true">{{ course.title.slice(0, 2) }}</span>
        </router-link>
        <div class="body">
          <h2 class="title">
            <router-link :to="`/courses/${course.id}`">{{ course.title }}</router-link>
          </h2>
          <p class="teacher">讲师 · {{ course.teacher_name }}</p>
          <p class="summary">{{ course.summary || '讲师还没有写简介.' }}</p>
          <p class="meta">
            <span v-if="course.price_mode === 'free'" class="tag free">免费</span>
            <template v-else>
              <span class="price-now">¥ {{ formatPrice(course.sale_price || course.list_price) }}</span>
              <span v-if="course.sale_price > 0" class="price-was">¥ {{ formatPrice(course.list_price) }}</span>
            </template>
            <span v-if="course.preview_available" class="tag preview">支持试看</span>
            <span class="learners">{{ course.learner_count }} 位学员</span>
          </p>
        </div>
      </li>
    </ul>

    <nav v-if="totalPages > 1" class="pager" aria-label="分页">
      <button type="button" class="btn" :disabled="page <= 1" @click="goto(page - 1)">上一页</button>
      <span class="page-indicator">{{ page }} / {{ totalPages }}</span>
      <button type="button" class="btn" :disabled="page >= totalPages" @click="goto(page + 1)">下一页</button>
    </nav>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import type {
  CategoryBreadcrumbDTO,
  CourseListItemDTO,
} from '@learn-site/contracts'
import { fetchCategoryCourses } from '@/api/learner'

defineOptions({ name: 'CategoryView' })

const route = useRoute()
const router = useRouter()
const category = ref<CategoryBreadcrumbDTO | null>(null)
const items = ref<CourseListItemDTO[]>([])
const total = ref(0)
const page = ref(1)
const limit = 20
const loading = ref(true)
const loadError = ref(false)

const totalPages = computed(() => Math.max(1, Math.ceil(total.value / limit)))

const lede = computed(() => {
  if (loading.value) return '正在铺开课程…'
  if (loadError.value) return '分类暂时读不到, 课室还在.'
  if (total.value === 0) return '还没有发布中的课程. 管理员发布后会出现在这里.'
  return `共 ${total.value} 门已发布课程, 默认按更新时间倒序.`
})

function formatPrice(n: number): string {
  return n.toFixed(2)
}

function goto(next: number): void {
  if (next === page.value) return
  router.replace({ query: { ...route.query, page: String(next) } })
}

async function load(): Promise<void> {
  const id = Number(route.params.id)
  if (!Number.isFinite(id) || id <= 0) {
    loadError.value = true
    loading.value = false
    return
  }
  const queryPage = Number(route.query.page)
  page.value = Number.isFinite(queryPage) && queryPage > 0 ? queryPage : 1
  loading.value = true
  loadError.value = false
  try {
    const { category: cat, list } = await fetchCategoryCourses(id, page.value, limit)
    category.value = cat
    items.value = list.items
    total.value = list.total
  } catch {
    loadError.value = true
  } finally {
    loading.value = false
  }
}

onMounted(load)
watch(() => [route.params.id, route.query.page], load)
</script>

<style scoped>
.page { display: grid; gap: 16px; }
.badge a { color: inherit; text-decoration: none; }
.display { margin: 0; }
.lede { color: var(--color-text-muted, #5b6472); margin: 0; }
.empty, .notice { color: var(--color-text-muted, #5b6472); }
.notice.error { color: #b42318; }

.course-grid {
  list-style: none;
  padding: 0;
  margin: 0;
  display: grid;
  gap: 16px;
  grid-template-columns: repeat(auto-fill, minmax(240px, 1fr));
}
.course-card {
  border: 1px solid var(--color-border, #e3e6ee);
  border-radius: 10px;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  background: var(--color-surface, #fff);
}
.cover {
  display: block;
  aspect-ratio: 16 / 9;
  background: var(--color-bg-soft, #eef1f7);
  overflow: hidden;
}
.cover img { width: 100%; height: 100%; object-fit: cover; display: block; }
.cover-fallback {
  display: flex;
  width: 100%;
  height: 100%;
  align-items: center;
  justify-content: center;
  font-size: 28px;
  color: var(--color-text-muted, #5b6472);
}
.body { padding: 12px 14px 14px; display: grid; gap: 6px; }
.title { margin: 0; font-size: 16px; }
.title a { color: inherit; text-decoration: none; }
.teacher { margin: 0; font-size: 13px; color: var(--color-text-muted, #5b6472); }
.summary {
  margin: 0;
  font-size: 13px;
  color: var(--color-text-muted, #5b6472);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}
.meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin: 4px 0 0 0;
  font-size: 13px;
}
.price-now { font-weight: 600; }
.price-was { color: var(--color-text-muted, #5b6472); text-decoration: line-through; }
.tag {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  background: var(--color-bg-soft, #eef1f7);
}
.tag.free { background: #e7f6ec; color: #137a3c; }
.tag.preview { background: #e6efff; color: #1e3a8a; }
.learners { color: var(--color-text-muted, #5b6472); margin-left: auto; }

.pager {
  display: flex;
  gap: 12px;
  align-items: center;
  justify-content: center;
  margin-top: 8px;
}
.btn {
  padding: 6px 12px;
  border: 1px solid var(--color-border, #d0d4dc);
  border-radius: 6px;
  background: transparent;
  cursor: pointer;
  font: inherit;
}
.btn[disabled] { opacity: 0.55; cursor: not-allowed; }
.page-indicator { color: var(--color-text-muted, #5b6472); }
</style>
