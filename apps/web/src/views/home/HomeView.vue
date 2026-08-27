<template>
  <main class="page home-page">
    <header class="home-masthead">
      <div class="masthead-copy">
        <p class="eyebrow"><span class="eyebrow-rule" />林间课室 · 学习目录</p>
        <h1 class="display hero-title">{{ intro?.title ?? '把每一次学习，收进自己的课程档案' }}</h1>
        <p class="lede hero-lede">
          {{ intro?.subtitle ?? '从一门课开始，沿着清晰的路径慢慢学会一件事。' }}
        </p>
        <div v-if="intro?.body_html" class="intro-prose prose" v-html="intro.body_html" />
        <div class="hero-actions">
          <a class="btn btn-primary" href="#course-shelf">浏览最新课程</a>
          <router-link class="btn btn-ghost" to="/maps">
            查看学习地图 <span aria-hidden="true">→</span>
          </router-link>
        </div>
        <p v-if="intro?.contact_email" class="contact">课程合作：{{ intro.contact_email }}</p>
      </div>

      <article v-if="featuredCourse" class="featured-course">
        <div class="featured-label"><span />最近更新</div>
        <router-link :to="`/courses/${featuredCourse.id}`" class="featured-cover">
          <img
            v-if="featuredCourse.cover_url"
            :src="featuredCourse.cover_url"
            :alt="featuredCourse.title"
          />
          <span v-else class="cover-fallback display">{{ featuredCourse.title.slice(0, 2) }}</span>
        </router-link>
        <div class="featured-body">
          <p class="featured-kicker">
            {{ featuredCourse.teacher_name }} · {{ featuredCourse.learner_count }} 位学员
          </p>
          <h2 class="display">
            <router-link :to="`/courses/${featuredCourse.id}`">
              {{
                featuredCourse.title
              }}
            </router-link>
          </h2>
          <p>{{ featuredCourse.summary || '打开课程，开始今天的学习。' }}</p>
        </div>
      </article>
      <div v-else class="featured-course featured-empty">
        <span class="featured-label"><span />课程展架</span>
        <p>课程封面会在发布后出现在这里。</p>
      </div>
    </header>

    <dl v-if="!loading" class="stats" aria-label="站点概览">
      <div class="stat">
        <dt>课程分类</dt>
        <dd>{{ categoryCount }}</dd>
        <span>按主题整理</span>
      </div>
      <div class="stat">
        <dt>近期课程</dt>
        <dd>{{ recentCourses.length }}</dd>
        <span>持续更新中</span>
      </div>
      <div class="stat">
        <dt>免费课程</dt>
        <dd>{{ freeCourseCount }}</dd>
        <span>适合先试试看</span>
      </div>
    </dl>

    <p v-if="loading" class="loading-line">正在整理课程目录…</p>
    <p v-else-if="error" class="notice">目录暂时读不到，课室还在。</p>

    <div v-else class="home-grid">
      <section class="panel trail-panel" aria-label="课程分类">
        <header class="panel-head">
          <p class="eyebrow"><span class="eyebrow-rule" />01 · 课程分类</p>
          <h2 class="panel-title display">从一条路径开始</h2>
          <p class="panel-lede">按主题浏览课程，最多三级分类，点击名称即可进入课程列表。</p>
        </header>
        <p v-if="categories.length === 0" class="empty">
          还没有启用中的分类。管理员发布课程后会出现在这里。
        </p>
        <ul v-else class="trail-root">
          <CategoryBranch v-for="node in categories" :key="node.id" :node="node" />
        </ul>
      </section>

      <section id="course-shelf" class="panel shelf-panel" aria-label="最新课程">
        <header class="panel-head">
          <div class="section-heading">
            <div>
              <p class="eyebrow"><span class="eyebrow-rule" />02 · 课程展架</p>
              <h2 class="panel-title display">最近更新的课程</h2>
            </div>
            <router-link class="text-link" to="/maps">
              按路径学习 <span aria-hidden="true">→</span>
            </router-link>
          </div>
          <p class="panel-lede">把课程当作一页页可收藏的学习档案，先看主题，再决定从哪里开始。</p>
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
import { computed, onMounted, ref } from 'vue';
import type { CategoryNode, CourseListItemDTO, SiteIntro } from '@learn-site/contracts';
import { fetchHome } from '@/api/learner';
import CategoryBranch from '@/views/home/CategoryBranch.vue';
import CourseShelfCard from '@/views/home/CourseShelfCard.vue';

const categories = ref<CategoryNode[]>([]);
const recentCourses = ref<CourseListItemDTO[]>([]);
const intro = ref<SiteIntro | null>(null);
const loading = ref(true);
const error = ref(false);

function countNodes(nodes: CategoryNode[]): number {
  return nodes.reduce((sum, node) => sum + 1 + countNodes(node.children), 0);
}

const categoryCount = computed(() => countNodes(categories.value));
const freeCourseCount = computed(
  () => recentCourses.value.filter((course) => course.price_mode === 'free').length,
);
const featuredCourse = computed(() => recentCourses.value[0] ?? null);

onMounted(async () => {
  try {
    const home = await fetchHome();
    categories.value = home.categories;
    recentCourses.value = home.recent_courses ?? [];
    intro.value = home.site_intro ?? null;
  } catch {
    error.value = true;
  } finally {
    loading.value = false;
  }
});
</script>

<style scoped>
.home-page {
  max-width: 1120px;
  padding-bottom: 96px;
}

.home-masthead {
  display: grid;
  grid-template-columns: minmax(0, 1.12fr) minmax(310px, 0.88fr);
  gap: 54px;
  align-items: center;
  min-height: 438px;
  margin: 0 0 28px;
  padding: 38px 0 32px;
  border-bottom: 1px solid var(--line);
}

.masthead-copy {
  min-width: 0;
}

.eyebrow {
  display: flex;
  align-items: center;
  gap: 9px;
  margin: 0 0 18px;
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 0.71rem;
  font-weight: 700;
  letter-spacing: 0.1em;
}

.eyebrow-rule {
  width: 24px;
  height: 2px;
  background: currentColor;
}

.hero-title {
  max-width: 12ch;
  margin: 0 0 16px;
  color: var(--pine-deep);
  font-size: 3.7rem;
  line-height: 1.12;
}

.hero-lede {
  max-width: 42ch;
  font-size: 1.04rem;
}

.intro-prose {
  max-width: 54ch;
  margin-top: 15px;
  font-size: 0.9rem;
}

.hero-actions {
  display: flex;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 26px;
}

.contact {
  margin: 20px 0 0;
  color: var(--muted);
  font-size: 0.78rem;
}

.featured-course {
  position: relative;
  min-width: 0;
  border: 1px solid var(--line);
  background: var(--surface);
  box-shadow:
    14px 14px 0 var(--paper-deep),
    var(--shadow);
  overflow: hidden;
  transform: rotate(1deg);
  transition:
    transform 0.25s ease,
    box-shadow 0.25s ease;
}

.featured-course:hover {
  transform: rotate(0deg) translateY(-4px);
  box-shadow:
    14px 18px 0 var(--paper-deep),
    0 22px 48px rgba(31, 60, 48, 0.14);
}

.featured-label {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 13px 16px 10px;
  color: var(--accent);
  font-family: var(--font-mono);
  font-size: 0.7rem;
  font-weight: 700;
  letter-spacing: 0.08em;
}

.featured-label > span {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  background: var(--accent);
}

.featured-cover {
  display: block;
  aspect-ratio: 1.48;
  overflow: hidden;
  background: var(--paper-deep);
}

.featured-cover img {
  display: block;
  width: 100%;
  height: 100%;
  object-fit: cover;
  transition: transform 0.45s ease;
}

.featured-course:hover .featured-cover img {
  transform: scale(1.035);
}

.featured-body {
  display: grid;
  gap: 7px;
  padding: 15px 18px 19px;
}

.featured-kicker {
  margin: 0;
  color: var(--muted);
  font-size: 0.75rem;
}

.featured-body h2 {
  margin: 0;
  font-size: 1.35rem;
  line-height: 1.32;
}

.featured-body h2 a {
  color: var(--ink);
  text-decoration: none;
}

.featured-body p:last-child {
  display: -webkit-box;
  min-height: 2.8em;
  margin: 0;
  overflow: hidden;
  color: var(--muted);
  font-size: 0.82rem;
  line-height: 1.6;
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
}

.featured-empty {
  display: grid;
  align-content: center;
  min-height: 370px;
  padding: 24px;
}

.featured-empty p {
  margin: 0;
  color: var(--muted);
  line-height: 1.7;
}

.stats {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  margin: 0 0 52px;
  border-top: 1px solid var(--line);
  border-bottom: 1px solid var(--line);
}

.stat {
  display: grid;
  grid-template-columns: auto 1fr;
  grid-template-rows: auto auto;
  column-gap: 12px;
  align-items: end;
  padding: 17px 20px;
  border-right: 1px solid var(--line);
}

.stat:last-child {
  border-right: 0;
}

.stat dt {
  grid-column: 1 / -1;
  margin: 0 0 5px;
  color: var(--muted);
  font-size: 0.74rem;
}

.stat dd {
  margin: 0;
  color: var(--pine-deep);
  font-family: var(--font-mono);
  font-size: 1.55rem;
  font-weight: 700;
  line-height: 1;
}

.stat span {
  color: var(--muted);
  font-size: 0.72rem;
}

.loading-line {
  padding: 40px 0;
  color: var(--muted);
  text-align: center;
}

.home-grid {
  display: grid;
  grid-template-columns: minmax(260px, 340px) 1fr;
  gap: 24px;
  align-items: start;
}

.panel {
  padding: 22px 20px 24px;
}

.panel-head {
  margin-bottom: 18px;
}

.panel-title {
  margin: 6px 0 7px;
  font-size: 1.35rem;
}

.section-heading {
  display: flex;
  align-items: end;
  justify-content: space-between;
  gap: 14px;
}

.text-link {
  flex-shrink: 0;
  color: var(--accent);
  font-size: 0.79rem;
  font-weight: 700;
  text-decoration: none;
}

.text-link:hover {
  color: var(--pine-deep);
}

.panel-lede {
  margin: 0;
  color: var(--muted);
  font-size: 0.85rem;
  line-height: 1.6;
}

.trail-root {
  margin: 0;
  padding: 0;
  list-style: none;
}

.shelf-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 18px;
}

.empty {
  margin: 0;
  padding: 14px 16px;
  border: 1px dashed var(--line);
  border-radius: 8px;
  background: rgba(255, 255, 255, 0.7);
  color: var(--muted);
  font-size: 0.88rem;
  line-height: 1.6;
}

@media (max-width: 860px) {
  .home-masthead {
    grid-template-columns: 1fr;
    gap: 34px;
    min-height: 0;
    padding-top: 28px;
  }

  .hero-title {
    max-width: 15ch;
    font-size: 3rem;
  }

  .featured-course {
    max-width: 520px;
  }

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

@media (max-width: 560px) {
  .hero-title {
    font-size: 2.5rem;
  }

  .hero-actions .btn {
    flex: 1 1 180px;
  }

  .stats {
    grid-template-columns: 1fr;
    margin-bottom: 34px;
  }

  .stat {
    border-right: 0;
    border-bottom: 1px solid var(--line);
  }

  .stat:last-child {
    border-bottom: 0;
  }

  .section-heading {
    align-items: start;
    flex-direction: column;
    gap: 3px;
  }
}
</style>
