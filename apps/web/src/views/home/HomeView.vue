<template>
  <main class="page home-page">
    <!-- 终端风格欢迎区 -->
    <header class="hero-terminal">
      <div class="scanline" aria-hidden="true" />
      <p class="prompt latin">
        <span class="prompt-user">guest@linjian</span>:<span class="prompt-path">~/campus</span>$
        browse --home
      </p>
      <h1 class="display hero-title">
        {{ intro?.title ?? '林间课室' }}
      </h1>
      <p class="lede hero-lede">
        {{ intro?.subtitle ?? '先选一条林间小径，再翻开课表贴纸。学习地图在主导航，不占首页。' }}
      </p>
      <div v-if="intro?.body_html" class="intro-prose prose" v-html="intro.body_html" />
      <p v-if="intro?.contact_email" class="contact">
        <span class="latin">contact</span> · {{ intro.contact_email }}
      </p>
      <dl v-if="!loading" class="stats" aria-label="站点概览">
        <div class="stat">
          <dt>分类</dt>
          <dd>{{ categoryCount }}</dd>
        </div>
        <div class="stat">
          <dt>课程</dt>
          <dd>{{ recentCourses.length }}</dd>
        </div>
        <div class="stat">
          <dt>免费</dt>
          <dd>{{ freeCourseCount }}</dd>
        </div>
      </dl>
    </header>

    <p v-if="loading" class="loading-line">正在铺开分类与课表…</p>
    <p v-else-if="error" class="notice">分类暂时读不到，课室还在。</p>

    <div v-else class="home-grid">
      <!-- 左侧：分类小径 -->
      <section class="panel trail-panel" aria-label="课程分类">
        <header class="panel-head">
          <p class="badge">林间索引</p>
          <h2 class="panel-title display">先选一条小径</h2>
          <p class="panel-lede">最多三级分类，点击即进入该分类下的课程列表。</p>
        </header>
        <p v-if="categories.length === 0" class="empty">
          还没有启用中的分类。管理员发布课程后会出现在这里。
        </p>
        <ul v-else class="trail-root">
          <CategoryBranch v-for="node in categories" :key="node.id" :node="node" />
        </ul>
      </section>

      <!-- 右侧：新课展架 -->
      <section class="panel shelf-panel" aria-label="最新课程">
        <header class="panel-head">
          <p class="badge">新课展架</p>
          <h2 class="panel-title display">翻开课表贴纸</h2>
          <p class="panel-lede">最近更新的已发布课程，封面、讲师与价格一目了然。</p>
        </header>
        <p v-if="recentCourses.length === 0" class="empty">
          还没有公开课程。管理员发布后会贴在这里。
        </p>
        <div v-else class="shelf-grid">
          <CourseShelfCard
            v-for="(course, index) in recentCourses"
            :key="course.id"
            :course="course"
            :index="index"
          />
        </div>
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import type { CategoryNode, CourseListItemDTO, SiteIntro } from '@learn-site/contracts'
import { fetchHome } from '@/api/learner'
import CategoryBranch from '@/views/home/CategoryBranch.vue'
import CourseShelfCard from '@/views/home/CourseShelfCard.vue'

const categories = ref<CategoryNode[]>([])
const recentCourses = ref<CourseListItemDTO[]>([])
const intro = ref<SiteIntro | null>(null)
const loading = ref(true)
const error = ref(false)

// 递归统计分类节点数
function countNodes(nodes: CategoryNode[]): number {
  return nodes.reduce((sum, node) => sum + 1 + countNodes(node.children), 0)
}

const categoryCount = computed(() => countNodes(categories.value))

const freeCourseCount = computed(
  () => recentCourses.value.filter((c) => c.price_mode === 'free').length,
)

onMounted(async () => {
  try {
    const home = await fetchHome()
    categories.value = home.categories
    recentCourses.value = home.recent_courses ?? []
    intro.value = home.site_intro ?? null
  } catch {
    error.value = true
  } finally {
    loading.value = false
  }
})
</script>

<style scoped>
.home-page {
  max-width: 1120px;
  padding-bottom: 96px;
}

.hero-terminal {
  position: relative;
  margin-bottom: 32px;
  padding: 28px 28px 24px;
  border-radius: 28px;
  border: 1px solid var(--line);
  background:
    linear-gradient(135deg, rgba(255, 255, 255, 0.88), rgba(247, 252, 251, 0.72)),
    repeating-linear-gradient(
      0deg,
      transparent,
      transparent 2px,
      rgba(18, 196, 200, 0.03) 2px,
      rgba(18, 196, 200, 0.03) 4px
    );
  box-shadow: 0 16px 48px rgba(18, 90, 78, 0.1);
  overflow: hidden;
  animation: hero-in 0.7s cubic-bezier(0.22, 1, 0.36, 1) both;
}

.scanline {
  pointer-events: none;
  position: absolute;
  inset: 0;
  background: linear-gradient(
    180deg,
    transparent 0%,
    rgba(18, 196, 200, 0.04) 48%,
    transparent 52%
  );
  animation: scan 6s linear infinite;
}

.prompt {
  margin: 0 0 12px;
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.75rem;
  color: var(--muted);
}

.prompt-user {
  color: var(--leaf);
}

.prompt-path {
  color: var(--cyan);
}

.hero-title {
  margin: 0 0 8px;
  font-size: clamp(1.85rem, 4vw, 2.6rem);
  line-height: 1.15;
}

.hero-lede {
  margin: 0;
  max-width: 52ch;
}

.intro-prose {
  margin-top: 14px;
  padding-top: 14px;
  border-top: 1px dashed var(--line);
  font-size: 0.92rem;
  line-height: 1.75;
  color: var(--muted);
}

.contact {
  margin: 12px 0 0;
  font-size: 0.82rem;
  color: var(--muted);
}

.contact .latin {
  color: var(--leaf);
  font-family: 'JetBrains Mono', monospace;
  font-size: 0.72rem;
}

.stats {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  margin: 20px 0 0;
  padding: 0;
}

.stat {
  min-width: 88px;
  padding: 10px 14px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.65);
  border: 1px solid var(--line);
}

.stat dt {
  margin: 0;
  font-size: 0.72rem;
  color: var(--muted);
  letter-spacing: 0.06em;
}

.stat dd {
  margin: 4px 0 0;
  font-family: 'JetBrains Mono', monospace;
  font-size: 1.35rem;
  font-weight: 600;
  color: var(--ink);
}

.loading-line {
  text-align: center;
  color: var(--muted);
  padding: 40px 0;
}

.home-grid {
  display: grid;
  gap: 24px;
  grid-template-columns: minmax(260px, 340px) 1fr;
  align-items: start;
}

.panel {
  background: rgba(255, 255, 255, 0.78);
  border: 1px solid var(--line);
  border-radius: 24px;
  box-shadow: 0 12px 40px rgba(18, 90, 78, 0.08);
  padding: 22px 20px 24px;
}

.panel-head {
  margin-bottom: 18px;
}

.panel-title {
  margin: 8px 0 6px;
  font-size: 1.35rem;
}

.panel-lede {
  margin: 0;
  font-size: 0.85rem;
  color: var(--muted);
  line-height: 1.6;
}

.trail-root {
  list-style: none;
  margin: 0;
  padding: 0;
}

.shelf-grid {
  display: grid;
  gap: 18px;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
}

.empty {
  padding: 14px 16px;
  border-radius: 14px;
  background: rgba(255, 255, 255, 0.7);
  border: 1px dashed var(--line);
  color: var(--muted);
  font-size: 0.88rem;
  line-height: 1.6;
  margin: 0;
}

@keyframes hero-in {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}

@keyframes scan {
  0% {
    transform: translateY(-100%);
  }
  100% {
    transform: translateY(100%);
  }
}

@media (max-width: 860px) {
  .home-grid {
    grid-template-columns: 1fr;
  }

  .trail-panel {
    order: 2;
  }

  .shelf-panel {
    order: 1;
  }
}
</style>
