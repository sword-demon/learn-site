<template>
  <main class="page home-page">
    <el-skeleton v-if="loading" animated :rows="6" />
    <el-alert
      v-else-if="error"
      title="目录暂时读不到，请稍后再试。"
      type="error"
      :closable="false"
      show-icon
    />

    <div v-else>
      <section v-if="session.loggedIn" class="learning-action" data-testid="learning-action">
        <el-skeleton v-if="actionLoading" animated :rows="2" />
        <el-alert
          v-else-if="actionError"
          title="下一步暂时读不到，请从课程目录继续。"
          type="warning"
          :closable="false"
          show-icon
        />
        <el-alert
          v-else-if="action?.availability === 'unavailable'"
          :title="action.availability_reason ?? '这个学习目标暂时不可用。'"
          type="info"
          :closable="false"
          show-icon
        />
        <div v-else-if="action" class="learning-action__body" :data-action-state="actionState">
          <div>
            <p class="learning-action__eyebrow">下一步行动</p>
            <h2>{{ action.title }}</h2>
            <p>{{ action.reason }}</p>
            <p v-if="actionState === 'degraded'" class="learning-action__degraded">
              学习状态暂时不完整，已展示服务端确认的可用入口。
            </p>
          </div>
          <router-link
            v-if="action.target.path"
            :to="action.target.path"
            class="learning-action__link"
          >
            继续
          </router-link>
        </div>
        <el-empty v-else description="暂时没有可继续的学习行动" />
      </section>
      <HomeBannerCarousel v-if="banners.length > 0" :banners="banners" :headline="bannerHeadline" />

      <div class="home-grid">
        <aside class="tree-panel home-sidebar" aria-label="课程分类">
          <div class="home-sidebar__head">
            <h2>拾阶目录</h2>
            <p>逐级而上</p>
          </div>
          <el-button
            text
            class="all-categories"
            :class="{ on: selectedId === null }"
            data-action="all-categories"
            @click="selectCategory(null)"
          >
            <span>全部分类</span>
            <span class="cnt">{{ allCourseTotal }}</span>
          </el-button>
          <el-tree
            class="category-tree"
            :data="categories"
            node-key="id"
            default-expand-all
            highlight-current
            :current-node-key="selectedId ?? undefined"
            :expand-on-click-node="false"
            @node-click="onCategoryNodeClick"
          >
            <template #default="{ data }">
              <span class="category-node">
                <span>{{ data.name }}</span>
                <span class="cnt">{{ countUnder(data.id) }}</span>
              </span>
            </template>
          </el-tree>
        </aside>

        <section aria-label="课程列表">
          <div class="home-list-head">
            <div class="crumbs home-list-head__crumbs">
              <router-link to="/">拾阶学社</router-link>
              <span class="sep">/</span>
              <span>{{ listTitle }}</span>
            </div>
            <div class="list-head home-list-head__title">
              <h2>
                {{ listTitle }} <span class="cnt">({{ courses.length }}门)</span>
              </h2>
            </div>
          </div>

          <el-skeleton v-if="listLoading" animated :rows="5" />
          <el-alert
            v-else-if="listError"
            title="课程列表暂时读不到。"
            type="error"
            :closable="false"
            show-icon
          />
          <el-empty
            v-else-if="courses.length === 0"
            description="这一类暂时还没有课程，换个分类看看吧"
          />
          <div v-else class="entry-list">
            <CourseEntryRow
              v-for="course in courses"
              :key="course.id"
              :course="course"
              :show-favorite="session.loggedIn"
            />
          </div>
        </section>
      </div>

      <section
        v-if="recommendedMaps.length > 0"
        class="home__map-rail"
        data-testid="recommended-map-rail"
        aria-label="推荐学习地图"
      >
        <header class="home__map-rail-head">
          <h2>推荐学习地图</h2>
          <router-link to="/maps" class="home__map-rail-more">查看全部 →</router-link>
        </header>
        <div class="home__map-grid">
          <article
            v-for="m in recommendedMaps"
            :key="m.id"
            class="home__map-card"
            :data-map-id="m.id"
          >
            <div class="home__map-card-cover">
              <img v-if="m.cover_url" :src="m.cover_url" :alt="m.title" />
              <img v-else :src="mapFallbackCover(m.id)" :alt="m.title" />
            </div>
            <div class="home__map-card-body">
              <h3>{{ m.title }}</h3>
              <p v-if="m.summary" class="home__map-card-summary">{{ m.summary }}</p>
              <router-link :to="`/maps/${m.id}`" class="home__map-card-cta">开始探索 →</router-link>
            </div>
          </article>
        </div>
      </section>
    </div>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useRoute, useRouter } from 'vue-router';
import type { CategoryNode, CourseListItemDTO, LearnerNextActionDTO } from '@learn-site/contracts';
import { fetchCategoryCourses } from '@/api/learner';
import { fetchNextAction } from '@/api/learningAction';
import { useLoginFamilyStore } from '@/api/login';
import { useHomeStore } from '@/stores/home';
import CourseEntryRow from '@/components/CourseEntryRow.vue';
import HomeBannerCarousel from '@/components/HomeBannerCarousel.vue';

const homeStore = useHomeStore();
const session = useLoginFamilyStore();
const route = useRoute();
const router = useRouter();
const { categories, recentCourses, banners, recommendedMaps, intro, loading, error } =
  storeToRefs(homeStore);

const bannerHeadline = computed(() => intro.value?.title?.trim() ?? '');

const selectedId = ref<number | null>(null);
const courses = ref<CourseListItemDTO[]>([]);
const allCourseTotal = ref(0);
const action = ref<Awaited<ReturnType<typeof fetchNextAction>>['action']>(null);
const actionState = ref<LearnerNextActionDTO['state'] | null>(null);
const actionLoading = ref(false);
const actionError = ref(false);
const listLoading = ref(false);
const listError = ref(false);
const MAP_FALLBACKS = [
  '/assets/stitch-map-scroll.jpg',
  '/assets/stitch-map-steps.jpg',
  '/assets/stitch-map-stars.jpg',
] as const;

function collectIds(nodes: CategoryNode[]): number[] {
  const ids: number[] = [];
  for (const node of nodes) {
    ids.push(node.id, ...collectIds(node.children));
  }
  return ids;
}

const categoryIndex = computed(() => {
  const map = new Map<number, CategoryNode>();
  function walk(nodes: CategoryNode[]): void {
    for (const node of nodes) {
      map.set(node.id, node);
      walk(node.children);
    }
  }
  walk(categories.value);
  return map;
});

function findPath(id: number): string[] {
  const path: string[] = [];
  function walk(nodes: CategoryNode[], trail: string[]): boolean {
    for (const node of nodes) {
      if (node.id === id) {
        path.push(...trail, node.name);
        return true;
      }
      if (node.children.length > 0 && walk(node.children, [...trail, node.name])) {
        return true;
      }
    }
    return false;
  }
  walk(categories.value, []);
  return path;
}

function countUnder(id: number): number {
  const node = categoryIndex.value.get(id);
  if (!node) return 0;
  const ids = new Set([id, ...collectIds(node.children)]);
  return recentCourses.value.filter((course) => ids.has(course.category_id)).length;
}

const listTitle = computed(() =>
  selectedId.value != null ? (findPath(selectedId.value).at(-1) ?? '分类课程') : '全部课程',
);

function parseCatQuery(): number | null {
  const raw = route.query.cat;
  const value = Array.isArray(raw) ? raw[0] : raw;
  if (!value) return null;
  const id = Number(value);
  return Number.isFinite(id) && id > 0 ? id : null;
}

async function loadCourses(categoryId: number | null): Promise<void> {
  listLoading.value = true;
  listError.value = false;
  try {
    if (categoryId == null) {
      courses.value = recentCourses.value;
      allCourseTotal.value = recentCourses.value.length;
      return;
    }
    const { list } = await fetchCategoryCourses(categoryId, 1, 100);
    courses.value = list.items;
  } catch {
    listError.value = true;
    courses.value = [];
  } finally {
    listLoading.value = false;
  }
}

function selectCategory(id: number | null): void {
  selectedId.value = id;
  const query = id == null ? {} : { cat: String(id) };
  void router.replace({ query });
}

function onCategoryNodeClick(node: CategoryNode): void {
  selectCategory(node.id);
}

function mapFallbackCover(id: number): string {
  return MAP_FALLBACKS[id % MAP_FALLBACKS.length] ?? MAP_FALLBACKS[0];
}

watch(
  () => route.query.cat,
  () => {
    selectedId.value = parseCatQuery();
    void loadCourses(selectedId.value);
  },
);

onMounted(async () => {
  await homeStore.load({ force: true });
  selectedId.value = parseCatQuery();
  allCourseTotal.value = recentCourses.value.length;
  await loadCourses(selectedId.value);
  if (session.loggedIn) {
    actionLoading.value = true;
    try {
      const result = await fetchNextAction();
      actionState.value = result.state;
      action.value = result.action ?? result.fallback;
    } catch {
      actionError.value = true;
    } finally {
      actionLoading.value = false;
    }
  }
});
</script>

<style scoped>
.home-page {
  padding-bottom: 48px;
}

.learning-action {
  margin-bottom: 24px;
  padding: 20px 24px;
  border: 1px solid var(--line);
  border-left: 4px solid var(--seal);
  background: var(--card);
}

.learning-action__body {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 20px;
}

.learning-action__body h2,
.learning-action__body p {
  margin: 0;
}

.learning-action__body h2 {
  margin-top: 4px;
  color: var(--seal);
}

.learning-action__body p:not(.learning-action__eyebrow):not(.learning-action__degraded) {
  margin-top: 8px;
  color: var(--ink-2);
}

.learning-action__eyebrow,
.learning-action__degraded {
  font-size: 12px;
  color: var(--ink-3);
}

.learning-action__degraded {
  margin-top: 8px !important;
}

.learning-action__link {
  flex: 0 0 auto;
  color: var(--seal);
  font-weight: 700;
}

@media (max-width: 640px) {
  .learning-action__body {
    align-items: flex-start;
    flex-direction: column;
  }
}

.home-sidebar {
  position: sticky;
  top: 80px;
  min-height: 600px;
  padding: 16px 0;
  border: 1px solid var(--line);
  border-right: 1px solid var(--line-2);
  border-radius: 0 12px 12px 0;
  background: var(--card);
}

.home-sidebar__head {
  padding: 0 16px 16px;
  margin-bottom: 8px;
}

.home-sidebar__head h2 {
  margin: 0;
  font-family: var(--serif);
  font-size: 24px;
  color: var(--seal);
}

.home-sidebar__head p {
  margin: 4px 0 0;
  font-size: 12px;
  color: var(--ink-3);
}

.home-list-head {
  margin-bottom: 16px;
}

.home-list-head__crumbs {
  margin-bottom: 8px;
  font-size: 12px;
}

.home-list-head__title {
  margin: 0;
  padding-bottom: 8px;
  border-bottom: 1px solid var(--line-2);
}

.home-list-head__title h2 {
  margin: 0;
  font-family: var(--serif);
  font-size: 32px;
  font-weight: 600;
}

.home-list-head__title .cnt {
  font-family: var(--sans);
  font-size: 16px;
  font-weight: 400;
  color: var(--ink-2);
}

.tree-panel {
  position: sticky;
  top: 20px;
}

.all-categories.el-button {
  width: 100%;
  min-height: 36px;
  justify-content: space-between;
  margin: 0 0 4px;
  padding: 6px 10px 6px 26px;
  color: var(--ink-2);
}

.all-categories.el-button.on {
  color: var(--seal);
  background: var(--seal-soft);
}

.category-tree {
  color: var(--ink-2);
  background: transparent;
}

.category-tree :deep(.el-tree-node__content) {
  min-height: 36px;
  border-radius: var(--r);
}

.category-tree :deep(.el-tree-node__content:hover),
.category-tree :deep(.el-tree-node.is-current > .el-tree-node__content) {
  color: var(--seal);
  background: var(--seal-soft);
}

.category-node {
  display: flex;
  flex: 1;
  min-width: 0;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding-right: 10px;
}

.home__map-rail {
  margin: 40px 0 0;
}

.home__map-rail-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  margin: 0 0 16px;
}

.home__map-rail-head h2 {
  margin: 0;
  font-size: 18px;
}

.home__map-rail-more {
  color: var(--seal, #409eff);
  font-size: 14px;
  text-decoration: none;
}

.home__map-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 16px;
}

.home__map-card {
  display: flex;
  flex-direction: column;
  border: 1px solid var(--line, #ebeef5);
  border-radius: var(--r, 8px);
  background: #fff;
  overflow: hidden;
  transition:
    transform 0.15s,
    box-shadow 0.15s;
}

.home__map-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.08);
}

.home__map-card-cover {
  aspect-ratio: 16 / 9;
  background: var(--card-2, #f5f7fa);
  display: flex;
  align-items: center;
  justify-content: center;
}

.home__map-card-cover img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.home__map-card-cover-fallback {
  font-size: 32px;
  font-weight: 600;
  color: var(--ink-2, #909399);
}

.home__map-card-body {
  padding: 16px;
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.home__map-card-body h3 {
  margin: 0;
  font-size: 16px;
  color: var(--ink, #303133);
}

.home__map-card-summary {
  margin: 0;
  font-size: 13px;
  color: var(--ink-2, #606266);
  line-height: 1.5;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
}

.home__map-card-cta {
  margin-top: auto;
  color: var(--seal, #409eff);
  font-size: 14px;
  text-decoration: none;
}
</style>
