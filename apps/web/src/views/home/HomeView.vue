<template>
  <main class="page home-page">
    <p v-if="loading" class="notice">正在整理课程目录…</p>
    <p v-else-if="error" class="notice">目录暂时读不到，请稍后再试。</p>

    <div v-else class="home-grid">
      <aside class="tree-panel" aria-label="课程分类">
        <h3>分类目录</h3>
        <ul class="tree">
          <li>
            <button
              type="button"
              class="tree-row"
              :class="{ on: selectedId === null }"
              @click="selectCategory(null)"
            >
              <span class="caret" style="visibility: hidden" aria-hidden="true">·</span>
              全部分类
              <span class="cnt">{{ allCourseTotal }}</span>
            </button>
          </li>
          <CategoryBranch
            v-for="node in categories"
            :key="node.id"
            :node="node"
            :selected-id="selectedId"
            :count-under="countUnder"
            @select="selectCategory"
          />
        </ul>
      </aside>

      <section aria-label="课程列表">
        <div class="list-head">
          <h2>{{ listTitle }}</h2>
          <span class="cnt">{{ courses.length }} 门课程</span>
        </div>
        <div class="crumbs" style="margin-bottom: 6px">{{ breadcrumbText }}</div>

        <p v-if="listLoading" class="notice">课程加载中…</p>
        <p v-else-if="listError" class="notice error">课程列表暂时读不到。</p>
        <div v-else-if="courses.length === 0" class="empty">
          <span class="serif">这一类暂时还没有课程</span>
          换个分类看看吧
        </div>
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
  </main>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { storeToRefs } from 'pinia';
import { useRoute, useRouter } from 'vue-router';
import type { CategoryNode, CourseListItemDTO } from '@learn-site/contracts';
import { fetchCategoryCourses } from '@/api/learner';
import { useLoginFamilyStore } from '@/api/login';
import { useHomeStore } from '@/stores/home';
import CourseEntryRow from '@/components/CourseEntryRow.vue';
import CategoryBranch from '@/views/home/CategoryBranch.vue';

const homeStore = useHomeStore();
const session = useLoginFamilyStore();
const route = useRoute();
const router = useRouter();
const { categories, recentCourses, loading, error } = storeToRefs(homeStore);

const selectedId = ref<number | null>(null);
const courses = ref<CourseListItemDTO[]>([]);
const allCourseTotal = ref(0);
const listLoading = ref(false);
const listError = ref(false);

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

const breadcrumbText = computed(() =>
  selectedId.value != null
    ? findPath(selectedId.value).join(' / ')
    : '拾阶学社 · 分类浏览',
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

watch(
  () => route.query.cat,
  () => {
    selectedId.value = parseCatQuery();
    void loadCourses(selectedId.value);
  },
);

onMounted(async () => {
  await homeStore.load();
  selectedId.value = parseCatQuery();
  allCourseTotal.value = recentCourses.value.length;
  await loadCourses(selectedId.value);
});
</script>

<style scoped>
.home-page {
  padding-bottom: 48px;
}

.tree-panel {
  position: sticky;
  top: 20px;
}
</style>
