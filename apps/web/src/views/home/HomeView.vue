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

    <div v-else class="home-grid">
      <aside class="tree-panel" aria-label="课程分类">
        <h3>分类目录</h3>
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
        <div class="list-head">
          <h2>{{ listTitle }}</h2>
          <span class="cnt">{{ courses.length }} 门课程</span>
        </div>
        <div class="crumbs" style="margin-bottom: 6px">{{ breadcrumbText }}</div>

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
  selectedId.value != null ? findPath(selectedId.value).join(' / ') : '拾阶学社 · 分类浏览',
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
</style>
