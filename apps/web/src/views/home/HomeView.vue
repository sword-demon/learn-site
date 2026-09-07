<template>
  <main class="page home-page">
    <header class="home-heading">
      <h1>{{ intro?.title || '拾阶学社' }}</h1>
      <p>{{ intro?.subtitle || '拾级而上，日进一阶。' }}</p>
    </header>
    <el-skeleton v-if="loading" animated :rows="6" />
    <el-alert v-else-if="error" title="目录暂时读不到，请稍后再试。" type="error" :closable="false" show-icon />
    <template v-else>
      <section v-if="session.loggedIn" class="learning-action" data-testid="learning-action">
        <el-skeleton v-if="actionLoading" animated :rows="2" />
        <el-alert v-else-if="actionError" title="下一步暂时读不到，请从课程目录继续。" type="warning" :closable="false" show-icon />
        <el-alert v-else-if="action?.availability === 'unavailable'" :title="action.availability_reason ?? '这个学习目标暂时不可用。'" type="info" :closable="false" show-icon />
        <div v-else-if="action" class="learning-action__body" :data-action-state="actionState">
          <el-icon class="learning-action__icon" :size="28"><Reading /></el-icon>
          <div class="learning-action__copy">
            <p class="learning-action__eyebrow">继续你的学习</p>
            <h2>{{ action.title }}</h2>
            <p>{{ action.reason }}</p>
            <p v-if="actionState === 'degraded'" class="learning-action__degraded">学习状态暂时不完整，已展示服务端确认的可用入口。</p>
          </div>
          <router-link v-if="action.target.path" :to="action.target.path" class="btn btn-primary learning-action__link">
            <el-icon><VideoPlay /></el-icon>继续学习
          </router-link>
        </div>
        <p v-else class="muted">暂时没有可继续的学习行动</p>
      </section>

      <section class="course-discovery" aria-label="课程列表">
        <header class="discovery-heading">
          <h2>发现课程</h2>
          <nav class="category-nav" aria-label="课程分类">
            <button type="button" :class="{ on: selectedId === null }" :aria-pressed="selectedId === null" data-action="all-categories" @click="selectCategory(null)">全部课程</button>
            <button v-for="category in categories" :key="category.id" type="button" :class="{ on: rootId === category.id }"
              :aria-pressed="rootId === category.id" :data-category-id="category.id" @click="selectCategory(category.id)">{{ category.name }}</button>
          </nav>
        </header>
        <div v-if="selectedId !== null" class="category-detail">
          <el-tree-select :model-value="selectedId" :data="categories" :props="{ label: 'name', value: 'id', children: 'children' }"
            node-key="id" check-strictly filterable :render-after-expand="false" aria-label="选择课程分类" placeholder="选择课程分类"
            @update:model-value="selectCategory" />
          <span class="muted">{{ categoryPath.join(' / ') }}</span>
        </div>
        <el-skeleton v-if="listLoading" animated :rows="5" />
        <el-alert v-else-if="listError" title="课程列表暂时读不到。" type="error" :closable="false" show-icon />
        <el-empty v-else-if="courses.length === 0" description="这一类暂时还没有课程，换个分类看看吧" />
        <div v-else class="course-grid">
          <CourseEntryRow v-for="course in courses" :key="course.id" :course="course" :show-favorite="session.loggedIn" />
        </div>
      </section>

      <section v-if="recommendedMaps.length > 0" class="home__map-rail" data-testid="recommended-map-rail" aria-label="推荐学习地图">
        <header class="discovery-heading">
          <h2>推荐学习地图</h2>
          <router-link to="/maps">查看全部 <el-icon><ArrowRight /></el-icon></router-link>
        </header>
        <div class="home__map-grid">
          <router-link v-for="map in recommendedMaps" :key="map.id" :to="`/maps/${map.id}`" class="home__map-card" :data-map-id="map.id">
            <img v-if="map.cover_url" :src="map.cover_url" :alt="map.title" />
            <el-icon v-else :size="28" class="map-icon"><Guide /></el-icon>
            <div><h3>{{ map.title }}</h3><p v-if="map.summary">{{ map.summary }}</p></div>
            <el-icon class="map-arrow"><ArrowRight /></el-icon>
          </router-link>
        </div>
      </section>
      <HomeBannerCarousel v-if="banners.length > 0" :banners="banners" />
    </template>
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useRoute, useRouter } from 'vue-router';
import { ArrowRight, Guide, Reading, VideoPlay } from '@element-plus/icons-vue';
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
const { categories, recentCourses, banners, recommendedMaps, intro, loading, error } = storeToRefs(homeStore);
const selectedId = computed(() => {
  const raw = Array.isArray(route.query.cat) ? route.query.cat[0] : route.query.cat;
  const id = Number(raw);
  return Number.isInteger(id) && id > 0 ? id : null;
});
function findPath(nodes: CategoryNode[], id: number): CategoryNode[] {
  for (const node of nodes) {
    if (node.id === id) return [node];
    const childPath = findPath(node.children, id);
    if (childPath.length) return [node, ...childPath];
  }
  return [];
}
const selectedPath = computed(() => selectedId.value === null ? [] : findPath(categories.value, selectedId.value));
const rootId = computed(() => selectedPath.value[0]?.id);
const categoryPath = computed(() => selectedPath.value.map((node) => node.name));
const courses = ref<CourseListItemDTO[]>([]);
const action = ref<Awaited<ReturnType<typeof fetchNextAction>>['action']>(null);
const actionState = ref<LearnerNextActionDTO['state'] | null>(null);
const actionLoading = ref(false);
const actionError = ref(false);
const listLoading = ref(false);
const listError = ref(false);
let courseRequest = 0;

async function loadCourses(): Promise<void> {
  const request = ++courseRequest;
  listLoading.value = true;
  listError.value = false;
  try {
    const items = selectedId.value === null ? recentCourses.value : (await fetchCategoryCourses(selectedId.value, 1, 100)).list.items;
    if (request === courseRequest) courses.value = items;
  } catch {
    if (request === courseRequest) { listError.value = true; courses.value = []; }
  } finally {
    if (request === courseRequest) listLoading.value = false;
  }
}
function selectCategory(id: number | null): void {
  void router.replace({ query: { ...route.query, cat: id === null ? undefined : String(id) } });
}
watch(selectedId, () => { void loadCourses(); });

onMounted(async () => {
  await homeStore.load({ force: true });
  await loadCourses();
  if (session.loggedIn) {
    actionLoading.value = true;
    try {
      const result = await fetchNextAction();
      actionState.value = result.state;
      action.value = result.action ?? result.fallback;
    } catch { actionError.value = true; }
    finally { actionLoading.value = false; }
  }
});
</script>

<style scoped>
.home-page { width: min(1440px, 100%); padding: 36px 32px 64px; }
.home-heading { margin-bottom: 32px; }
.home-heading h1 { margin: 0 0 6px; font-size: 30px; font-weight: 750; line-height: 1.4; }
.home-heading p { margin: 0; color: var(--ink-2); font-size: 16px; }
.learning-action { padding: 24px; border: 1px solid var(--line); border-radius: 8px; margin-bottom: 40px; }
.learning-action__body { display: flex; align-items: center; gap: 24px; }
.learning-action__icon { color: var(--seal); background: var(--seal-soft); width: 72px; height: 72px; border-radius: 6px; flex-shrink: 0; }
.learning-action__copy { min-width: 0; flex: 1; }
.learning-action h2 { font-size: 20px; margin: 2px 0 4px; }
.learning-action p { margin: 0; color: var(--ink-2); }
.learning-action .learning-action__eyebrow { font-size: 12px; color: var(--seal); }
.learning-action__degraded { font-size: 12px; }
.learning-action__link { flex-shrink: 0; gap: 8px; }
.discovery-heading { display: flex; align-items: center; justify-content: space-between; gap: 24px; border-bottom: 1px solid var(--line); padding-bottom: 18px; margin-bottom: 24px; }
.discovery-heading h2 { margin: 0; font-size: 24px; white-space: nowrap; }
.discovery-heading > a { display: flex; gap: 8px; align-items: center; }
.category-nav { display: flex; justify-content: flex-end; flex-wrap: wrap; column-gap: 24px; row-gap: 8px; }
.category-nav button { border: 0; border-bottom: 2px solid transparent; padding: 6px 0; background: transparent; color: var(--ink-2); cursor: pointer; }
.category-nav button.on { border-color: var(--seal); color: var(--seal); font-weight: 600; }
.category-nav button:hover { color: var(--seal); }
.category-detail { display: flex; align-items: center; gap: 16px; margin-bottom: 24px; }
.category-detail :deep(.el-select) { width: 220px; }
.course-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 24px; }
.home__map-rail { margin-top: 48px; }
.home__map-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 20px; }
.home__map-card { display: flex; gap: 20px; align-items: center; padding: 24px; border: 1px solid var(--line); border-radius: 8px; background: var(--paper-2); color: var(--ink); }
.home__map-card:hover { border-color: var(--seal); }
.home__map-card img { width: 72px; height: 56px; object-fit: cover; border-radius: 4px; }
.home__map-card h3 { font-size: 17px; margin: 0 0 6px; }
.home__map-card p { font-size: 14px; margin: 0; color: var(--ink-2); }
.home__map-card > div { flex: 1; min-width: 0; }
.map-icon { color: var(--seal); flex-shrink: 0; }
.home__map-card:nth-child(even) .map-icon { color: var(--gold); }
.map-arrow { flex-shrink: 0; }
.home-banner-carousel { margin-top: 48px; margin-bottom: 0; }
</style>
