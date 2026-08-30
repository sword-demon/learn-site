# 学习端 Figma 全覆盖重写实施计划

> ⚠️ **DEPRECATED 2026-08-31**: 此计划基于错误假设（"从零定义 contracts 和 API"），与现有 `packages/contracts/src/*` 28 个 DTO 和 `apps/web/src/api/*` 完整 fetch* 体系严重脱节。已废弃。重写版见同目录新文件。

---

# 学习端 Figma 全覆盖重写实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 按 Figma 设计稿全覆盖重写 `apps/web` 全部 11 个桌面级页面，分 10 个独立可回滚的 commit 完成。

**Architecture:** Vue 3 SFC + Pinia + Element Plus + Tailwind；前端仅改；后端字段不足用 `// ponytail: backend gap` 占位；contracts 按需扩展；每页一个 vitest 单测；通过 `pnpm -F @learn-site/web typecheck/lint/test/build` 四项质量门。

**Tech Stack:** Vue 3.5、Vite 5、TypeScript strict 5.6、Element Plus 2.8.4、Tailwind 3.4、Pinia 2.2、Zod 3.23、vitest 2.1、@vue/test-utils 2.4、happy-dom 20。

**Spec:** `docs/superpowers/specs/2026-08-31-figma-web-redesign-design.md`

---

## 文件结构

### 新建

| 路径 | 用途 |
| --- | --- |
| `apps/web/src/components/PageHeader.vue` | 二级页顶栏（logo / 搜索 / 用户入口） |
| `apps/web/src/components/EmptyState.vue` | 统一空状态（图 + 文 + CTA） |
| `apps/web/src/components/SkeletonBlock.vue` | 统一骨架屏 |
| `apps/web/src/components/LearnerTabs.vue` | 学员中心 Tab 切换容器 |
| `apps/web/src/views/me/StudentCenterView.vue` | 学员中心 6-Tab 整合视图 |
| `apps/web/src/views/auth/LoginRegisterView.vue` | 登录/注册合并视图 |

### 删除或转为 redirect

| 路径 | 动作 |
| --- | --- |
| `apps/web/src/views/me/MyLearningView.vue` | 删除 |
| `apps/web/src/views/me/FavoritesView.vue` | 删除 |
| `apps/web/src/views/me/MyOrdersView.vue` | 删除 |
| `apps/web/src/views/me/MessagesView.vue` | 删除 |
| `apps/web/src/views/me/CheckinListView.vue` | 删除 |
| `apps/web/src/views/me/AccountView.vue` | 删除 |
| `apps/web/src/views/auth/LoginView.vue` | 转为 redirect 到 `/login` |
| `apps/web/src/views/auth/RegisterView.vue` | 转为 redirect 到 `/login?tab=register` |

### 整体重写

| 路径 |
| --- |
| `apps/web/src/views/home/HomeView.vue` |
| `apps/web/src/views/maps/MapListView.vue` |
| `apps/web/src/views/maps/MapDetailView.vue` |
| `apps/web/src/views/catalog/CourseDetailView.vue` |
| `apps/web/src/views/learn/LessonView.vue` |
| `apps/web/src/views/checkout/CheckoutView.vue` |

### contracts 扩展

| 路径 | 动作 |
| --- | --- |
| `packages/contracts/src/home.ts` | 扩 HomeViewSchema |
| `packages/contracts/src/map.ts` | 新建（如不存在） |
| `packages/contracts/src/course.ts` | 新建（如不存在） |
| `packages/contracts/src/lesson.ts` | 新建（如不存在） |
| `packages/contracts/src/checkout.ts` | 新建（如不存在） |
| `packages/contracts/src/me.ts` | 新建（如不存在） |
| `packages/contracts/src/index.ts` | re-export |

### 测试新增

每个 view/store 至少 1 个 `*.test.ts`，路径同源码 `__tests__` 或 `.spec.ts` 约定（沿用项目现有规则，参考 `apps/web/tests/HomeView.test.ts`）。

### 修改

| 路径 | 动作 |
| --- | --- |
| `apps/web/src/router/index.ts` | 删旧路由、合并登录注册、新增学员中心 Tab 子路由 |
| `apps/web/src/stores/*.ts` | 新增/扩 store |
| `apps/web/src/api/*.ts` | 按需新增/扩 |
| `tasks/lessons.md` | 追加 backend gap 汇总 |

---

## 通用约定

每页 commit 前必跑：

```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```

commit 信息格式遵循仓库约定：

```
<type>(scope): <subject>
```

参考最近 commit：`feat(admin)`、`fix(admin)`、`docs(README)`、`feat(admin,api)`。

---

## Task 1: 脚手架（4 个新组件 + contracts 扩展）

**Files:**
- Create: `apps/web/src/components/PageHeader.vue` + `apps/web/tests/PageHeader.test.ts`
- Create: `apps/web/src/components/EmptyState.vue` + `apps/web/tests/EmptyState.test.ts`
- Create: `apps/web/src/components/SkeletonBlock.vue` + `apps/web/tests/SkeletonBlock.test.ts`
- Create: `apps/web/src/components/LearnerTabs.vue` + `apps/web/tests/LearnerTabs.test.ts`
- Create/Modify: `packages/contracts/src/{home,map,course,lesson,checkout,me,index}.ts`

### 1.1 PageHeader 组件

- [ ] **Step 1: 写测试**

`apps/web/tests/PageHeader.test.ts`：

```ts
import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import PageHeader from '@/components/PageHeader.vue';

describe('PageHeader', () => {
  it('renders logo, search and user slot when authenticated', () => {
    const wrapper = mount(PageHeader, {
      props: { loggedIn: true },
      slots: { user: '<span class="user-mock">me</span>' },
    });
    expect(wrapper.find('.page-header__logo').exists()).toBe(true);
    expect(wrapper.find('input[type=search]').exists()).toBe(true);
    expect(wrapper.find('.user-mock').exists()).toBe(true);
  });

  it('hides user slot when not logged in', () => {
    const wrapper = mount(PageHeader, { props: { loggedIn: false } });
    expect(wrapper.find('.user-mock').exists()).toBe(false);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test PageHeader`
Expected: FAIL "Cannot find module"

- [ ] **Step 3: 写最小实现**

`apps/web/src/components/PageHeader.vue`：

```vue
<script setup lang="ts">
import { ElInput, ElButton } from 'element-plus';

defineProps<{ loggedIn: boolean }>();
</script>

<template>
  <header class="page-header bg-paper border-b border-ink/10">
    <div class="page-header__logo font-serif text-xl">拾阶学社</div>
    <ElInput placeholder="搜索课程 / 地图" class="page-header__search max-w-md" />
    <slot v-if="loggedIn" name="user">
      <ElButton>登录</ElButton>
    </slot>
  </header>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test PageHeader`
Expected: PASS

- [ ] **Step 5: 暂存但不 commit，本任务所有组件完成后统一 commit**

### 1.2 EmptyState 组件

- [ ] **Step 1: 写测试**

`apps/web/tests/EmptyState.test.ts`：

```ts
import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import EmptyState from '@/components/EmptyState.vue';

describe('EmptyState', () => {
  it('renders title, description and CTA when action label present', () => {
    const wrapper = mount(EmptyState, {
      props: { title: '暂无数据', description: '请稍后再来', actionLabel: '刷新' },
    });
    expect(wrapper.text()).toContain('暂无数据');
    expect(wrapper.text()).toContain('请稍后再来');
    expect(wrapper.find('button').text()).toBe('刷新');
  });

  it('omits button when no action label', () => {
    const wrapper = mount(EmptyState, { props: { title: '空' } });
    expect(wrapper.find('button').exists()).toBe(false);
  });

  it('emits action event on click', async () => {
    const wrapper = mount(EmptyState, {
      props: { title: '空', actionLabel: '刷新' },
    });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('action')).toHaveLength(1);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test EmptyState`
Expected: FAIL

- [ ] **Step 3: 写最小实现**

`apps/web/src/components/EmptyState.vue`：

```vue
<script setup lang="ts">
import { ElButton } from 'element-plus';

defineProps<{ title: string; description?: string; actionLabel?: string }>();
defineEmits<{ action: [] }>();
</script>

<template>
  <div class="empty-state text-center py-16 px-6">
    <div class="empty-state__art w-32 h-32 mx-auto mb-4 bg-ink/5 rounded-full" />
    <h3 class="text-lg font-medium text-ink">{{ title }}</h3>
    <p v-if="description" class="mt-2 text-sm text-ink/60">{{ description }}</p>
    <ElButton v-if="actionLabel" type="primary" class="mt-6" @click="$emit('action')">
      {{ actionLabel }}
    </ElButton>
  </div>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test EmptyState`
Expected: PASS

- [ ] **Step 5: 暂存**

### 1.3 SkeletonBlock 组件

- [ ] **Step 1: 写测试**

`apps/web/tests/SkeletonBlock.test.ts`：

```ts
import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import SkeletonBlock from '@/components/SkeletonBlock.vue';

describe('SkeletonBlock', () => {
  it('renders N rows by count prop', () => {
    const wrapper = mount(SkeletonBlock, { props: { count: 4 } });
    expect(wrapper.findAll('.skeleton-row')).toHaveLength(4);
  });

  it('defaults to 3 rows when no count', () => {
    const wrapper = mount(SkeletonBlock);
    expect(wrapper.findAll('.skeleton-row')).toHaveLength(3);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test SkeletonBlock`
Expected: FAIL

- [ ] **Step 3: 写最小实现**

`apps/web/src/components/SkeletonBlock.vue`：

```vue
<script setup lang="ts">
withDefaults(defineProps<{ count?: number }>(), { count: 3 });
</script>

<template>
  <div class="skeleton-block space-y-3">
    <div
      v-for="i in count"
      :key="i"
      class="skeleton-row h-12 bg-ink/5 rounded animate-pulse"
    />
  </div>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test SkeletonBlock`
Expected: PASS

- [ ] **Step 5: 暂存**

### 1.4 LearnerTabs 组件

- [ ] **Step 1: 写测试**

`apps/web/tests/LearnerTabs.test.ts`：

```ts
import { mount } from '@vue/test-utils';
import { describe, it, expect } from 'vitest';
import LearnerTabs from '@/components/LearnerTabs.vue';

describe('LearnerTabs', () => {
  const tabs = [
    { key: 'learning', label: '学习中' },
    { key: 'favorites', label: '收藏' },
  ];

  it('renders one tab per item', () => {
    const wrapper = mount(LearnerTabs, { props: { tabs, modelValue: 'learning' } });
    expect(wrapper.findAll('.learner-tab')).toHaveLength(2);
  });

  it('marks active tab with modelValue', () => {
    const wrapper = mount(LearnerTabs, { props: { tabs, modelValue: 'favorites' } });
    expect(wrapper.find('.learner-tab--active').text()).toBe('收藏');
  });

  it('emits update:modelValue when tab clicked', async () => {
    const wrapper = mount(LearnerTabs, { props: { tabs, modelValue: 'learning' } });
    await wrapper.findAll('.learner-tab')[1].trigger('click');
    expect(wrapper.emitted('update:modelValue')?.[0]).toEqual(['favorites']);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test LearnerTabs`
Expected: FAIL

- [ ] **Step 3: 写最小实现**

`apps/web/src/components/LearnerTabs.vue`：

```vue
<script setup lang="ts" generic="K extends string">
defineProps<{
  tabs: ReadonlyArray<{ key: K; label: string }>;
  modelValue: K;
}>();
defineEmits<{ 'update:modelValue': [value: K] }>();
</script>

<template>
  <nav class="learner-tabs flex gap-1 border-b border-ink/10">
    <button
      v-for="t in tabs"
      :key="t.key"
      type="button"
      class="learner-tab px-4 py-2 text-sm"
      :class="{ 'learner-tab--active border-b-2 border-vermilion text-vermilion': modelValue === t.key }"
      @click="$emit('update:modelValue', t.key)"
    >
      {{ t.label }}
    </button>
  </nav>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test LearnerTabs`
Expected: PASS

- [ ] **Step 5: 暂存**

### 1.5 contracts 扩展

- [ ] **Step 1: 写 contracts 测试**

`packages/contracts/src/__tests__/home.test.ts`（如不存在则新建）：

```ts
import { describe, it, expect } from 'vitest';
import { HomeViewSchema } from '../home';

describe('HomeViewSchema', () => {
  it('validates minimal payload', () => {
    const r = HomeViewSchema.safeParse({
      banners: [],
      maps: [],
      recommendations: [],
      categories: [],
    });
    expect(r.success).toBe(true);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/contracts test home`
Expected: FAIL "no test" 或 schema 缺失

- [ ] **Step 3: 扩 home.ts**

`packages/contracts/src/home.ts`：

```ts
import { z } from 'zod';

export const BannerSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  imageUrl: z.string().url(),
  link: z.string().url().optional(),
});

export const MapListItemSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  coverImage: z.string().url(),
  courseCount: z.number().int().nonnegative(),
});

export const CourseSummarySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  coverImage: z.string().url(),
  category: z.string(),
  learnerCount: z.number().int().nonnegative(),
});

export const CategorySchema = z.object({
  id: z.number().int().positive(),
  name: z.string(),
  iconUrl: z.string().url().optional(),
});

export const HomeViewSchema = z.object({
  banners: z.array(BannerSchema),
  maps: z.array(MapListItemSchema),
  recommendations: z.array(CourseSummarySchema),
  categories: z.array(CategorySchema),
});

export type HomeView = z.infer<typeof HomeViewSchema>;
```

- [ ] **Step 4: 创建 map.ts**

`packages/contracts/src/map.ts`：

```ts
import { z } from 'zod';

export const MapStepSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  courseId: z.number().int().positive().optional(),
  courseTitle: z.string().optional(),
  summary: z.string().optional(),
});

export const MapDetailSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  coverImage: z.string().url(),
  description: z.string(),
  steps: z.array(MapStepSchema),
});

export const MapListItemSchema = MapDetailSchema.pick({
  id: true,
  title: true,
  coverImage: true,
}).extend({ courseCount: z.number().int().nonnegative() });

export type MapDetail = z.infer<typeof MapDetailSchema>;
export type MapListItem = z.infer<typeof MapListItemSchema>;
```

- [ ] **Step 5: 创建 course.ts / lesson.ts / checkout.ts / me.ts**

`packages/contracts/src/course.ts`：

```ts
import { z } from 'zod';

export const CourseLessonSummarySchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  durationSeconds: z.number().int().nonnegative(),
  isFreePreview: z.boolean().optional(),
});

export const CourseDetailSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  coverImage: z.string().url(),
  description: z.string(),
  category: z.string(),
  instructorName: z.string(),
  lessonCount: z.number().int().nonnegative(),
  learnerCount: z.number().int().nonnegative(),
  priceCents: z.number().int().nonnegative(),
  lessons: z.array(CourseLessonSummarySchema),
});

export type CourseDetail = z.infer<typeof CourseDetailSchema>;
```

`packages/contracts/src/lesson.ts`：

```ts
import { z } from 'zod';

export const LessonMediaSchema = z.object({
  videoUrl: z.string().url().optional(),
  pdfUrl: z.string().url().optional(),
  markdown: z.string().optional(),
});

export const LessonSchema = z.object({
  id: z.number().int().positive(),
  courseId: z.number().int().positive(),
  title: z.string(),
  order: z.number().int().nonnegative(),
  media: LessonMediaSchema,
  prevLessonId: z.number().int().positive().nullable().optional(),
  nextLessonId: z.number().int().positive().nullable().optional(),
});

export type Lesson = z.infer<typeof LessonSchema>;
```

`packages/contracts/src/checkout.ts`：

```ts
import { z } from 'zod';

export const CheckoutSummarySchema = z.object({
  courseId: z.number().int().positive(),
  courseTitle: z.string(),
  coverImage: z.string().url(),
  priceCents: z.number().int().nonnegative(),
  discountCents: z.number().int().nonnegative().optional(),
  finalCents: z.number().int().nonnegative(),
});

export type CheckoutSummary = z.infer<typeof CheckoutSummarySchema>;
```

`packages/contracts/src/me.ts`：

```ts
import { z } from 'zod';
import { CourseSummarySchema } from './course';

export const OrderSummarySchema = z.object({
  id: z.number().int().positive(),
  courseTitle: z.string(),
  coverImage: z.string().url(),
  priceCents: z.number().int().nonnegative(),
  createdAt: z.string(),
  status: z.enum(['pending', 'paid', 'refunded', 'cancelled']),
});

export const MessageItemSchema = z.object({
  id: z.number().int().positive(),
  title: z.string(),
  body: z.string(),
  createdAt: z.string(),
  read: z.boolean(),
});

export const LearnerCenterSchema = z.object({
  profile: z.object({
    nickname: z.string(),
    avatarUrl: z.string().url().optional(),
  }),
  learning: z.array(CourseSummarySchema.extend({ progressPercent: z.number().min(0).max(100) })),
  favorites: z.array(CourseSummarySchema),
  orders: z.array(OrderSummarySchema),
  messages: z.array(MessageItemSchema),
});

export type LearnerCenter = z.infer<typeof LearnerCenterSchema>;
```

- [ ] **Step 6: 改 index.ts**

`packages/contracts/src/index.ts` 追加：

```ts
export * from './home';
export * from './map';
export * from './course';
export * from './lesson';
export * from './checkout';
export * from './me';
```

- [ ] **Step 7: 跑测试，确认通过**

Run: `pnpm -F @learn-site/contracts test`
Expected: PASS

- [ ] **Step 8: 跑质量门 + commit**

Run:
```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```

Run:
```bash
git add apps/web/src/components/PageHeader.vue apps/web/src/components/EmptyState.vue apps/web/src/components/SkeletonBlock.vue apps/web/src/components/LearnerTabs.vue apps/web/tests/PageHeader.test.ts apps/web/tests/EmptyState.test.ts apps/web/tests/SkeletonBlock.test.ts apps/web/tests/LearnerTabs.test.ts packages/contracts/src/home.ts packages/contracts/src/map.ts packages/contracts/src/course.ts packages/contracts/src/lesson.ts packages/contracts/src/checkout.ts packages/contracts/src/me.ts packages/contracts/src/index.ts packages/contracts/src/__tests__/home.test.ts
git commit -m "feat(web): 添加 PageHeader/EmptyState/SkeletonBlock/LearnerTabs 组件与 contracts 扩展"
```

---

## Task 2: 首页组（commit 2）

**Files:**
- Modify: `apps/web/src/stores/home.ts`
- Modify: `apps/web/src/views/home/HomeView.vue`
- Modify/Add: `apps/web/src/api/learner.ts`（按需新增 `getHomeView`）
- Modify: `apps/web/tests/HomeView.test.ts` + `apps/web/tests/HomeStore.test.ts`

### 2.1 HomeStore 重写

- [ ] **Step 1: 写测试**

`apps/web/tests/HomeStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useHomeStore } from '@/stores/home';

vi.mock('@/api/learner', () => ({
  getHomeView: vi.fn(),
}));

import { getHomeView } from '@/api/learner';

describe('useHomeStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('starts with idle state', () => {
    const store = useHomeStore();
    expect(store.loading).toBe(false);
    expect(store.error).toBeNull();
    expect(store.data).toBeNull();
  });

  it('loads and stores home data', async () => {
    const payload = {
      banners: [],
      maps: [],
      recommendations: [],
      categories: [],
    };
    vi.mocked(getHomeView).mockResolvedValue(payload);
    const store = useHomeStore();
    await store.load();
    expect(store.data).toEqual(payload);
    expect(store.loading).toBe(false);
  });

  it('captures error on failure', async () => {
    vi.mocked(getHomeView).mockRejectedValue(new Error('boom'));
    const store = useHomeStore();
    await store.load();
    expect(store.error?.message).toBe('boom');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test HomeStore`
Expected: FAIL（store 缺 load/error/data 字段）

- [ ] **Step 3: 重写 home store**

`apps/web/src/stores/home.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { HomeView } from '@learn-site/contracts';
import { getHomeView } from '@/api/learner';

export const useHomeStore = defineStore('home', () => {
  const data = ref<HomeView | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load() {
    loading.value = true;
    error.value = null;
    try {
      data.value = await getHomeView();
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { data, loading, error, load };
});
```

- [ ] **Step 4: 补 API（若 learner.ts 缺 getHomeView）**

`apps/web/src/api/learner.ts` 追加（若不存在）：

```ts
import { http } from './http';
import { HomeViewSchema, type HomeView } from '@learn-site/contracts';

export async function getHomeView(): Promise<HomeView> {
  const { data } = await http.get('/api/learner/v1/home');
  return HomeViewSchema.parse(data);
}
```

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test HomeStore`
Expected: PASS

### 2.2 HomeView 重写

- [ ] **Step 1: 写测试**

修改 `apps/web/tests/HomeView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import HomeView from '@/views/home/HomeView.vue';

vi.mock('@/api/learner', () => ({ getHomeView: vi.fn() }));
import { getHomeView } from '@/api/learner';

describe('HomeView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(getHomeView).mockReset();
  });

  it('shows skeleton while loading', () => {
    mount(HomeView);
    expect(document.querySelector('.skeleton-block')).toBeTruthy();
  });

  it('shows empty state when data is empty', async () => {
    vi.mocked(getHomeView).mockResolvedValue({
      banners: [],
      maps: [],
      recommendations: [],
      categories: [],
    });
    const wrapper = mount(HomeView);
    await flushPromises();
    expect(wrapper.text()).toContain('暂无内容');
  });

  it('renders sections when data loaded', async () => {
    vi.mocked(getHomeView).mockResolvedValue({
      banners: [{ id: 1, title: 'B', imageUrl: 'https://x/a.jpg' }],
      maps: [{ id: 1, title: 'Map', coverImage: 'https://x/m.jpg', courseCount: 3 }],
      recommendations: [],
      categories: [],
    });
    const wrapper = mount(HomeView);
    await flushPromises();
    expect(wrapper.text()).toContain('Map');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test HomeView`
Expected: FAIL

- [ ] **Step 3: 重写 HomeView**

`apps/web/src/views/home/HomeView.vue`：

```vue
<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useHomeStore } from '@/stores/home';
import HomeBannerCarousel from '@/components/HomeBannerCarousel.vue';
import EmptyState from '@/components/EmptyState.vue';
import SkeletonBlock from '@/components/SkeletonBlock.vue';
import { ElButton } from 'element-plus';

const store = useHomeStore();
const { data, loading, error } = storeToRefs(store);

const isEmpty = computed(
  () =>
    !!data.value &&
    data.value.banners.length === 0 &&
    data.value.maps.length === 0 &&
    data.value.recommendations.length === 0,
);

onMounted(() => store.load());
</script>

<template>
  <main class="home-view max-w-screen-xl mx-auto px-6 py-8">
    <SkeletonBlock v-if="loading" :count="6" />
    <EmptyState
      v-else-if="isEmpty"
      title="暂无内容"
      description="管理员还未发布课程/地图，先逛逛分类吧"
      action-label="刷新"
      @action="store.load()"
    />
    <template v-else-if="data">
      <HomeBannerCarousel v-if="data.banners.length" :banners="data.banners" />
      <section v-if="data.maps.length" class="mt-8">
        <h2 class="text-xl font-serif mb-4">学习地图</h2>
        <div class="grid grid-cols-3 gap-4">
          <article v-for="m in data.maps" :key="m.id" class="rounded overflow-hidden border border-ink/10">
            <img :src="m.coverImage" :alt="m.title" class="w-full h-40 object-cover" />
            <div class="p-4">
              <h3>{{ m.title }}</h3>
              <p class="text-sm text-ink/60">{{ m.courseCount }} 课程</p>
            </div>
          </article>
        </div>
      </section>
      <section v-if="data.recommendations.length" class="mt-8">
        <h2 class="text-xl font-serif mb-4">推荐课程</h2>
        <div class="grid grid-cols-4 gap-4">
          <article v-for="c in data.recommendations" :key="c.id" class="rounded overflow-hidden border border-ink/10">
            <img :src="c.coverImage" :alt="c.title" class="w-full h-32 object-cover" />
            <div class="p-3">
              <h3 class="text-sm">{{ c.title }}</h3>
              <p class="text-xs text-ink/60">{{ c.learnerCount }} 人在学</p>
            </div>
          </article>
        </div>
      </section>
    </template>
    <ElButton v-if="error" type="warning" @click="store.load()">重试</ElButton>
  </main>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test HomeView`
Expected: PASS

- [ ] **Step 5: 跑质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/home.ts apps/web/src/views/home/HomeView.vue apps/web/src/api/learner.ts apps/web/tests/HomeView.test.ts apps/web/tests/HomeStore.test.ts
git commit -m "feat(web): 重写首页 HomeView 含空/加载分支"
```

---

## Task 3: 学习地图列表（commit 3）

**Files:**
- Create: `apps/web/src/stores/maps.ts`
- Create: `apps/web/tests/MapListStore.test.ts`
- Modify: `apps/web/src/views/maps/MapListView.vue`
- Modify: `apps/web/tests/MapListView.test.ts`

### 3.1 MapListStore

- [ ] **Step 1: 写测试**

`apps/web/tests/MapListStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useMapListStore } from '@/stores/maps';

vi.mock('@/api/learner', () => ({ listMaps: vi.fn() }));
import { listMaps } from '@/api/learner';

describe('useMapListStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('loads maps', async () => {
    vi.mocked(listMaps).mockResolvedValue([
      { id: 1, title: 'M', coverImage: 'https://x/m.jpg', courseCount: 2 },
    ]);
    const store = useMapListStore();
    await store.load();
    expect(store.items).toHaveLength(1);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test MapListStore`
Expected: FAIL

- [ ] **Step 3: 写 store**

`apps/web/src/stores/maps.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { MapListItem } from '@learn-site/contracts';
import { listMaps } from '@/api/learner';

export const useMapListStore = defineStore('map-list', () => {
  const items = ref<MapListItem[]>([]);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load() {
    loading.value = true;
    error.value = null;
    try {
      items.value = await listMaps();
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { items, loading, error, load };
});
```

- [ ] **Step 4: 在 api/learner.ts 补 listMaps**

```ts
import { z } from 'zod';
import { MapListItemSchema } from '@learn-site/contracts';

export async function listMaps(): Promise<MapListItem[]> {
  const { data } = await http.get('/api/learner/v1/learning-maps');
  return z.array(MapListItemSchema).parse(data);
}
```

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test MapListStore`
Expected: PASS

### 3.2 MapListView

- [ ] **Step 1: 写测试**

`apps/web/tests/MapListView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import MapListView from '@/views/maps/MapListView.vue';

vi.mock('@/api/learner', () => ({ listMaps: vi.fn() }));
import { listMaps } from '@/api/learner';

describe('MapListView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(listMaps).mockReset();
  });

  it('renders map cards', async () => {
    vi.mocked(listMaps).mockResolvedValue([
      { id: 1, title: 'Map A', coverImage: 'https://x/a.jpg', courseCount: 3 },
    ]);
    const wrapper = mount(MapListView);
    await flushPromises();
    expect(wrapper.text()).toContain('Map A');
  });

  it('shows empty state when no maps', async () => {
    vi.mocked(listMaps).mockResolvedValue([]);
    const wrapper = mount(MapListView);
    await flushPromises();
    expect(wrapper.text()).toContain('暂无地图');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test MapListView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/maps/MapListView.vue`：

```vue
<script setup lang="ts">
import { onMounted } from 'vue';
import { storeToRefs } from 'pinia';
import { useMapListStore } from '@/stores/maps';
import EmptyState from '@/components/EmptyState.vue';
import SkeletonBlock from '@/components/SkeletonBlock.vue';

const store = useMapListStore();
const { items, loading } = storeToRefs(store);

onMounted(() => store.load());
</script>

<template>
  <main class="map-list max-w-screen-xl mx-auto px-6 py-8">
    <h1 class="text-2xl font-serif mb-6">学习地图</h1>
    <SkeletonBlock v-if="loading" :count="6" />
    <EmptyState
      v-else-if="items.length === 0"
      title="暂无地图"
      description="地图正在策划中，敬请期待"
    />
    <div v-else class="grid grid-cols-3 gap-6">
      <router-link
        v-for="m in items"
        :key="m.id"
        :to="`/maps/${m.id}`"
        class="rounded overflow-hidden border border-ink/10 hover:shadow-md"
      >
        <img :src="m.coverImage" :alt="m.title" class="w-full h-48 object-cover" />
        <div class="p-4">
          <h3 class="font-medium">{{ m.title }}</h3>
          <p class="text-sm text-ink/60 mt-1">{{ m.courseCount }} 门课程</p>
        </div>
      </router-link>
    </div>
  </main>
</template>
```

- [ ] **Step 4: 跑测试，确认通过 + 跑质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/maps.ts apps/web/src/views/maps/MapListView.vue apps/web/src/api/learner.ts apps/web/tests/MapListStore.test.ts apps/web/tests/MapListView.test.ts
git commit -m "feat(web): 重写学习地图列表 MapListView"
```

---

## Task 4: 学习地图详情（commit 4）

**Files:**
- Modify: `apps/web/src/stores/maps.ts`（追加 useMapDetailStore）
- Modify: `apps/web/src/views/maps/MapDetailView.vue`
- Modify: `apps/web/tests/MapDetailView.test.ts`

### 4.1 useMapDetailStore

- [ ] **Step 1: 写测试** 追加到 `apps/web/tests/MapListStore.test.ts`（改名为 `maps.test.ts` 或新建 `MapDetailStore.test.ts`）

```ts
import { useMapDetailStore } from '@/stores/maps';

describe('useMapDetailStore', () => {
  it('loads by id and caches', async () => {
    vi.mocked(listMaps).mockResolvedValue([]);
    const store = useMapDetailStore();
    await store.load(42);
    expect(store.current?.id).toBe(42);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test MapDetail`
Expected: FAIL

- [ ] **Step 3: 扩 store**

追加到 `apps/web/src/stores/maps.ts`：

```ts
import { getMap } from '@/api/learner';
import type { MapDetail } from '@learn-site/contracts';

export const useMapDetailStore = defineStore('map-detail', () => {
  const current = ref<MapDetail | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load(id: number) {
    loading.value = true;
    error.value = null;
    try {
      current.value = await getMap(id);
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { current, loading, error, load };
});
```

- [ ] **Step 4: 在 api/learner.ts 补 getMap**

```ts
import { MapDetailSchema } from '@learn-site/contracts';

export async function getMap(id: number): Promise<MapDetail> {
  const { data } = await http.get(`/api/learner/v1/learning-maps/${id}`);
  return MapDetailSchema.parse(data);
}
```

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test MapDetail`
Expected: PASS

### 4.2 MapDetailView

- [ ] **Step 1: 写测试**

`apps/web/tests/MapDetailView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import MapDetailView from '@/views/maps/MapDetailView.vue';

vi.mock('@/api/learner', () => ({ getMap: vi.fn() }));
import { getMap } from '@/api/learner';

describe('MapDetailView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(getMap).mockReset();
  });

  it('loads by route id', async () => {
    vi.mocked(getMap).mockResolvedValue({
      id: 7,
      title: 'Map',
      coverImage: 'https://x/m.jpg',
      description: 'desc',
      steps: [{ id: 1, title: 'S1' }],
    });
    const wrapper = mount(MapDetailView, {
      global: { mocks: { $route: { params: { id: '7' } } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('Map');
    expect(wrapper.text()).toContain('S1');
  });

  it('shows not-found when id is NaN', async () => {
    const wrapper = mount(MapDetailView, {
      global: { mocks: { $route: { params: { id: 'abc' } } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('无效');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test MapDetailView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/maps/MapDetailView.vue`：

```vue
<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useMapDetailStore } from '@/stores/maps';
import SkeletonBlock from '@/components/SkeletonBlock.vue';
import { ElButton } from 'element-plus';

const route = useRoute();
const store = useMapDetailStore();
const { current, loading, error } = storeToRefs(store);

const mapId = computed<number | null>(() => {
  const raw = route.params.id;
  const n = Number(Array.isArray(raw) ? raw[0] : raw);
  return Number.isFinite(n) && n > 0 ? n : null;
});

async function load() {
  if (mapId.value === null) return;
  await store.load(mapId.value);
}

onMounted(load);
watch(mapId, load);
</script>

<template>
  <main class="map-detail max-w-screen-lg mx-auto px-6 py-8">
    <template v-if="mapId === null">
      <p class="text-ink/60">无效的地图 id</p>
    </template>
    <SkeletonBlock v-else-if="loading" :count="6" />
    <template v-else-if="current">
      <img :src="current.coverImage" :alt="current.title" class="w-full h-64 object-cover rounded" />
      <h1 class="text-3xl font-serif mt-6">{{ current.title }}</h1>
      <p class="mt-4 text-ink/80">{{ current.description }}</p>
      <ol class="mt-8 space-y-3">
        <li v-for="(s, i) in current.steps" :key="s.id" class="border-l-2 border-vermilion pl-4">
          <span class="text-ink/40">第 {{ i + 1 }} 步</span>
          <h3 class="font-medium">{{ s.title }}</h3>
          <p v-if="s.summary" class="text-sm text-ink/60">{{ s.summary }}</p>
          <router-link v-if="s.courseId" :to="`/courses/${s.courseId}`" class="text-vermilion text-sm">
            进入课程 →
          </router-link>
        </li>
      </ol>
    </template>
    <ElButton v-if="error" type="warning" @click="load">重试</ElButton>
  </main>
</template>
```

- [ ] **Step 4: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/maps.ts apps/web/src/views/maps/MapDetailView.vue apps/web/src/api/learner.ts apps/web/tests/MapDetailStore.test.ts apps/web/tests/MapDetailView.test.ts apps/web/tests/MapListStore.test.ts
git commit -m "feat(web): 重写学习地图详情 MapDetailView"
```

---

## Task 5: 课程详情（commit 5）

**Files:**
- Create: `apps/web/src/stores/course.ts`
- Modify: `apps/web/src/views/catalog/CourseDetailView.vue`
- Modify: `apps/web/tests/CourseDetailView.test.ts`

### 5.1 useCourseStore

- [ ] **Step 1: 写测试** `apps/web/tests/CourseStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCourseStore } from '@/stores/course';

vi.mock('@/api/learner', () => ({ getCourse: vi.fn() }));
import { getCourse } from '@/api/learner';

describe('useCourseStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('loads course', async () => {
    vi.mocked(getCourse).mockResolvedValue({
      id: 1, title: 'C', coverImage: 'https://x/c.jpg',
      description: 'd', category: 'cat', instructorName: 'T',
      lessonCount: 0, learnerCount: 0, priceCents: 0, lessons: [],
    });
    const store = useCourseStore();
    await store.load(1);
    expect(store.current?.title).toBe('C');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test CourseStore`
Expected: FAIL

- [ ] **Step 3: 写 store**

`apps/web/src/stores/course.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { CourseDetail } from '@learn-site/contracts';
import { getCourse } from '@/api/learner';

export const useCourseStore = defineStore('course', () => {
  const current = ref<CourseDetail | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load(id: number) {
    loading.value = true;
    error.value = null;
    try {
      current.value = await getCourse(id);
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { current, loading, error, load };
});
```

- [ ] **Step 4: 在 api/learner.ts 补 getCourse**

```ts
import { CourseDetailSchema } from '@learn-site/contracts';

export async function getCourse(id: number): Promise<CourseDetail> {
  const { data } = await http.get(`/api/learner/v1/courses/${id}`);
  return CourseDetailSchema.parse(data);
}
```

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test CourseStore`
Expected: PASS

### 5.2 CourseDetailView

- [ ] **Step 1: 写测试** `apps/web/tests/CourseDetailView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import CourseDetailView from '@/views/catalog/CourseDetailView.vue';

vi.mock('@/api/learner', () => ({ getCourse: vi.fn() }));
import { getCourse } from '@/api/learner';

describe('CourseDetailView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(getCourse).mockReset();
  });

  it('renders title and price', async () => {
    vi.mocked(getCourse).mockResolvedValue({
      id: 1, title: 'Course X', coverImage: 'https://x/c.jpg',
      description: 'd', category: 'cat', instructorName: 'T',
      lessonCount: 5, learnerCount: 100, priceCents: 9900,
      lessons: [{ id: 1, title: 'L1', durationSeconds: 600 }],
    });
    const wrapper = mount(CourseDetailView, {
      global: { mocks: { $route: { params: { id: '1' } } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('Course X');
    expect(wrapper.text()).toContain('¥99');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test CourseDetailView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/catalog/CourseDetailView.vue`：

```vue
<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useCourseStore } from '@/stores/course';
import SkeletonBlock from '@/components/SkeletonBlock.vue';
import { ElButton } from 'element-plus';

const route = useRoute();
const store = useCourseStore();
const { current, loading, error } = storeToRefs(store);

const courseId = computed<number | null>(() => {
  const raw = route.params.id;
  const n = Number(Array.isArray(raw) ? raw[0] : raw);
  return Number.isFinite(n) && n > 0 ? n : null;
});

const priceYuan = computed(() =>
  current.value ? (current.value.priceCents / 100).toFixed(2) : '0.00',
);

async function load() {
  if (courseId.value !== null) await store.load(courseId.value);
}
onMounted(load);
watch(courseId, load);
</script>

<template>
  <main class="course-detail max-w-screen-xl mx-auto px-6 py-8">
    <template v-if="courseId === null">
      <p>无效的课程 id</p>
    </template>
    <SkeletonBlock v-else-if="loading" :count="8" />
    <article v-else-if="current" class="grid grid-cols-3 gap-8">
      <div class="col-span-2">
        <img :src="current.coverImage" :alt="current.title" class="w-full rounded" />
        <h1 class="text-3xl font-serif mt-6">{{ current.title }}</h1>
        <p class="mt-2 text-ink/60">讲师：{{ current.instructorName }}</p>
        <p class="mt-4 text-ink/80 whitespace-pre-line">{{ current.description }}</p>
        <section class="mt-8">
          <h2 class="text-xl font-serif mb-4">课程目录</h2>
          <ol class="space-y-2">
            <li v-for="(l, i) in current.lessons" :key="l.id" class="flex justify-between border-b border-ink/10 py-2">
              <span>第 {{ i + 1 }} 课 · {{ l.title }}</span>
              <span class="text-sm text-ink/60">{{ Math.round(l.durationSeconds / 60) }} 分钟</span>
            </li>
          </ol>
        </section>
      </div>
      <aside class="border border-ink/10 rounded p-6 h-fit sticky top-8">
        <p class="text-3xl font-serif text-vermilion">¥{{ priceYuan }}</p>
        <ElButton type="primary" size="large" class="w-full mt-4" @click="$router.push(`/checkout/${current.id}`)">
          立即购买
        </ElButton>
        <ElButton size="large" class="w-full mt-2" @click="$router.push(`/learn/${current.id}/${current.lessons[0]?.id ?? 1}`)">
          试看
        </ElButton>
      </aside>
    </article>
    <ElButton v-if="error" type="warning" @click="load">重试</ElButton>
  </main>
</template>
```

- [ ] **Step 4: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/course.ts apps/web/src/views/catalog/CourseDetailView.vue apps/web/src/api/learner.ts apps/web/tests/CourseStore.test.ts apps/web/tests/CourseDetailView.test.ts
git commit -m "feat(web): 重写课程详情 CourseDetailView"
```

---

## Task 6: 学习页（commit 6）

**Files:**
- Create: `apps/web/src/stores/lesson.ts`
- Modify: `apps/web/src/views/learn/LessonView.vue`
- Modify: `apps/web/tests/LessonView.test.ts`

### 6.1 useLessonStore

- [ ] **Step 1: 写测试** `apps/web/tests/LessonStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useLessonStore } from '@/stores/lesson';

vi.mock('@/api/learner', () => ({ deliverLesson: vi.fn() }));
import { deliverLesson } from '@/api/learner';

describe('useLessonStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('loads lesson', async () => {
    vi.mocked(deliverLesson).mockResolvedValue({
      id: 1, courseId: 1, title: 'L', order: 1,
      media: { videoUrl: 'https://x/v.mp4' },
    });
    const store = useLessonStore();
    await store.load(1, 1);
    expect(store.current?.title).toBe('L');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test LessonStore`
Expected: FAIL

- [ ] **Step 3: 写 store**

`apps/web/src/stores/lesson.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { Lesson } from '@learn-site/contracts';
import { deliverLesson } from '@/api/learner';

export const useLessonStore = defineStore('lesson', () => {
  const current = ref<Lesson | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load(courseId: number, lessonId: number) {
    loading.value = true;
    error.value = null;
    try {
      current.value = await deliverLesson(courseId, lessonId);
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { current, loading, error, load };
});
```

- [ ] **Step 4: api/learner.ts 补 deliverLesson**

```ts
import { LessonSchema } from '@learn-site/contracts';

export async function deliverLesson(courseId: number, lessonId: number): Promise<Lesson> {
  const { data } = await http.get(
    `/api/learner/v1/courses/${courseId}/lessons/${lessonId}`,
  );
  return LessonSchema.parse(data);
}
```

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test LessonStore`
Expected: PASS

### 6.2 LessonView

- [ ] **Step 1: 写测试** `apps/web/tests/LessonView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import LessonView from '@/views/learn/LessonView.vue';

vi.mock('@/api/learner', () => ({ deliverLesson: vi.fn() }));
import { deliverLesson } from '@/api/learner';

describe('LessonView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(deliverLesson).mockReset();
  });

  it('renders video for video lesson', async () => {
    vi.mocked(deliverLesson).mockResolvedValue({
      id: 1, courseId: 1, title: 'L1', order: 1,
      media: { videoUrl: 'https://x/v.mp4' },
    });
    const wrapper = mount(LessonView, {
      global: { mocks: { $route: { params: { courseId: '1', lessonId: '1' } } } },
    });
    await flushPromises();
    expect(wrapper.find('video').exists()).toBe(true);
  });

  it('rejects non-finite ids', async () => {
    const wrapper = mount(LessonView, {
      global: { mocks: { $route: { params: { courseId: 'abc', lessonId: '1' } } } },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('无效');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test LessonView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/learn/LessonView.vue`：

```vue
<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useLessonStore } from '@/stores/lesson';
import VideoPlayer from '@/components/VideoPlayer.vue';
import PdfViewer from '@/components/PdfViewer.vue';
import MarkdownRenderer from '@/components/MarkdownRenderer.vue';
import SkeletonBlock from '@/components/SkeletonBlock.vue';
import { ElButton } from 'element-plus';

const route = useRoute();
const store = useLessonStore();
const { current, loading, error } = storeToRefs(store);

const ids = computed<{ courseId: number | null; lessonId: number | null }>(() => {
  const toNum = (v: unknown) => {
    const n = Number(Array.isArray(v) ? v[0] : v);
    return Number.isFinite(n) && n > 0 ? n : null;
  };
  return { courseId: toNum(route.params.courseId), lessonId: toNum(route.params.lessonId) };
});

async function load() {
  const { courseId, lessonId } = ids.value;
  if (courseId !== null && lessonId !== null) await store.load(courseId, lessonId);
}
onMounted(load);
watch(ids, load, { deep: true });
</script>

<template>
  <main class="lesson-view max-w-screen-lg mx-auto px-6 py-8">
    <template v-if="ids.courseId === null || ids.lessonId === null">
      <p>无效的课节</p>
    </template>
    <SkeletonBlock v-else-if="loading" :count="4" />
    <article v-else-if="current">
      <h1 class="text-2xl font-serif mb-4">{{ current.title }}</h1>
      <VideoPlayer v-if="current.media.videoUrl" :src="current.media.videoUrl" />
      <PdfViewer v-else-if="current.media.pdfUrl" :src="current.media.pdfUrl" />
      <MarkdownRenderer v-else-if="current.media.markdown" :source="current.media.markdown" />
      <nav class="mt-8 flex justify-between">
        <ElButton v-if="current.prevLessonId" @click="$router.push(`/learn/${current.courseId}/${current.prevLessonId}`)">
          上一课
        </ElButton>
        <ElButton v-if="current.nextLessonId" type="primary" @click="$router.push(`/learn/${current.courseId}/${current.nextLessonId}`)">
          下一课
        </ElButton>
      </nav>
    </article>
    <ElButton v-if="error" type="warning" @click="load">重试</ElButton>
  </main>
</template>
```

- [ ] **Step 4: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/lesson.ts apps/web/src/views/learn/LessonView.vue apps/web/src/api/learner.ts apps/web/tests/LessonStore.test.ts apps/web/tests/LessonView.test.ts
git commit -m "feat(web): 重写学习页 LessonView"
```

---

## Task 7: 结算（commit 7）

**Files:**
- Create: `apps/web/src/stores/checkout.ts`
- Modify: `apps/web/src/views/checkout/CheckoutView.vue`
- Modify: `apps/web/tests/CheckoutView.test.ts`

### 7.1 useCheckoutStore

- [ ] **Step 1: 写测试** `apps/web/tests/CheckoutStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCheckoutStore } from '@/stores/checkout';

vi.mock('@/api/learner', () => ({
  getCheckoutSummary: vi.fn(),
  createOrder: vi.fn(),
}));
import { getCheckoutSummary, createOrder } from '@/api/learner';

describe('useCheckoutStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('loads summary and submits order', async () => {
    vi.mocked(getCheckoutSummary).mockResolvedValue({
      courseId: 1, courseTitle: 'C', coverImage: 'https://x/c.jpg',
      priceCents: 9900, finalCents: 9900,
    });
    vi.mocked(createOrder).mockResolvedValue({ orderId: 99 });
    const store = useCheckoutStore();
    await store.loadSummary(1);
    await store.submit();
    expect(store.orderId).toBe(99);
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test CheckoutStore`
Expected: FAIL

- [ ] **Step 3: 写 store**

`apps/web/src/stores/checkout.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { CheckoutSummary } from '@learn-site/contracts';
import { getCheckoutSummary, createOrder } from '@/api/learner';

export const useCheckoutStore = defineStore('checkout', () => {
  const summary = ref<CheckoutSummary | null>(null);
  const orderId = ref<number | null>(null);
  const loading = ref(false);
  const submitting = ref(false);
  const error = ref<Error | null>(null);

  async function loadSummary(courseId: number) {
    loading.value = true;
    error.value = null;
    try {
      summary.value = await getCheckoutSummary(courseId);
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  async function submit() {
    if (!summary.value) return;
    submitting.value = true;
    error.value = null;
    try {
      const result = await createOrder(summary.value.courseId);
      orderId.value = result.orderId;
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      submitting.value = false;
    }
  }

  return { summary, orderId, loading, submitting, error, loadSummary, submit };
});
```

- [ ] **Step 4: api/learner.ts 补**

```ts
import { CheckoutSummarySchema } from '@learn-site/contracts';

export async function getCheckoutSummary(courseId: number): Promise<CheckoutSummary> {
  const { data } = await http.get(`/api/learner/v1/courses/${courseId}/checkout-summary`);
  return CheckoutSummarySchema.parse(data);
}

export async function createOrder(courseId: number): Promise<{ orderId: number }> {
  const { data } = await http.post(`/api/learner/v1/courses/${courseId}/orders`);
  return data as { orderId: number };
}
```

（如后端无 `/checkout-summary` 端点，使用 `// ponytail: backend gap` 注释，前端用课程详情数据合成 summary）

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test CheckoutStore`
Expected: PASS

### 7.2 CheckoutView

- [ ] **Step 1: 写测试** `apps/web/tests/CheckoutView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import CheckoutView from '@/views/checkout/CheckoutView.vue';

vi.mock('@/api/learner', () => ({
  getCheckoutSummary: vi.fn(),
  createOrder: vi.fn(),
}));
import { getCheckoutSummary, createOrder } from '@/api/learner';

describe('CheckoutView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(getCheckoutSummary).mockReset();
    vi.mocked(createOrder).mockReset();
  });

  it('submits order and navigates', async () => {
    vi.mocked(getCheckoutSummary).mockResolvedValue({
      courseId: 1, courseTitle: 'C', coverImage: 'https://x/c.jpg',
      priceCents: 9900, finalCents: 9900,
    });
    vi.mocked(createOrder).mockResolvedValue({ orderId: 99 });
    const push = vi.fn();
    const wrapper = mount(CheckoutView, {
      global: {
        mocks: {
          $route: { params: { courseId: '1' } },
          $router: { push },
        },
      },
    });
    await flushPromises();
    await wrapper.find('button.checkout-submit').trigger('click');
    await flushPromises();
    expect(push).toHaveBeenCalledWith('/me/orders');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test CheckoutView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/checkout/CheckoutView.vue`：

```vue
<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { storeToRefs } from 'pinia';
import { useCheckoutStore } from '@/stores/checkout';
import SkeletonBlock from '@/components/SkeletonBlock.vue';
import { ElButton } from 'element-plus';

const route = useRoute();
const router = useRouter();
const store = useCheckoutStore();
const { summary, loading, submitting, error, orderId } = storeToRefs(store);

const courseId = computed<number | null>(() => {
  const raw = route.params.courseId;
  const n = Number(Array.isArray(raw) ? raw[0] : raw);
  return Number.isFinite(n) && n > 0 ? n : null;
});

async function load() {
  if (courseId.value !== null) await store.loadSummary(courseId.value);
}

async function submit() {
  await store.submit();
  if (orderId.value !== null) router.push('/me/orders');
}

onMounted(load);
watch(courseId, load);
</script>

<template>
  <main class="checkout max-w-screen-md mx-auto px-6 py-8">
    <template v-if="courseId === null">
      <p>无效的课程</p>
    </template>
    <SkeletonBlock v-else-if="loading" :count="4" />
    <article v-else-if="summary">
      <h1 class="text-2xl font-serif">结算</h1>
      <div class="mt-6 border border-ink/10 rounded p-6 flex gap-4">
        <img :src="summary.coverImage" :alt="summary.courseTitle" class="w-32 h-20 object-cover rounded" />
        <div class="flex-1">
          <h2>{{ summary.courseTitle }}</h2>
          <p class="mt-2 text-vermilion text-xl">¥{{ (summary.finalCents / 100).toFixed(2) }}</p>
        </div>
      </div>
      <ElButton
        type="primary"
        size="large"
        class="checkout-submit w-full mt-6"
        :loading="submitting"
        @click="submit"
      >
        确认下单
      </ElButton>
      <ElButton v-if="error" type="warning" class="mt-2" @click="load">重试</ElButton>
    </article>
  </main>
</template>
```

- [ ] **Step 4: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/checkout.ts apps/web/src/views/checkout/CheckoutView.vue apps/web/src/api/learner.ts apps/web/tests/CheckoutStore.test.ts apps/web/tests/CheckoutView.test.ts
git commit -m "feat(web): 重写结算 CheckoutView"
```

---

## Task 8: 登录注册合并（commit 8）

**Files:**
- Create: `apps/web/src/views/auth/LoginRegisterView.vue`
- Modify: `apps/web/src/views/auth/LoginView.vue`（转为 redirect）
- Modify: `apps/web/src/views/auth/RegisterView.vue`（转为 redirect）
- Modify: `apps/web/tests/LoginRegisterView.test.ts`
- Modify: `apps/web/src/router/index.ts`

### 8.1 LoginRegisterView

- [ ] **Step 1: 写测试** `apps/web/tests/LoginRegisterView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import LoginRegisterView from '@/views/auth/LoginRegisterView.vue';

vi.mock('@/api/login', () => ({ login: vi.fn(), register: vi.fn() }));
import { login, register } from '@/api/login';

describe('LoginRegisterView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(login).mockReset();
    vi.mocked(register).mockReset();
  });

  it('defaults to login tab', () => {
    const wrapper = mount(LoginRegisterView);
    expect(wrapper.find('.tab--active').text()).toContain('登录');
  });

  it('switches to register tab', async () => {
    const wrapper = mount(LoginRegisterView);
    await wrapper.findAll('button.tab').at(1)!.trigger('click');
    expect(wrapper.find('.tab--active').text()).toContain('注册');
  });

  it('calls login on submit', async () => {
    vi.mocked(login).mockResolvedValue({ token: 't' });
    const wrapper = mount(LoginRegisterView);
    await wrapper.find('input[name=phone]').setValue('13800000000');
    await wrapper.find('input[name=password]').setValue('pwd');
    await wrapper.find('form').trigger('submit.prevent');
    await flushPromises();
    expect(login).toHaveBeenCalled();
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test LoginRegisterView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/auth/LoginRegisterView.vue`：

```vue
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { ElInput, ElButton, ElForm, ElFormItem } from 'element-plus';
import { login, register } from '@/api/login';

const route = useRoute();
const router = useRouter();

type Tab = 'login' | 'register';
const tab = ref<Tab>(route.query.tab === 'register' ? 'register' : 'login');

const phone = ref('');
const password = ref('');
const captcha = ref('');

const submitting = ref(false);

async function submit() {
  submitting.value = true;
  try {
    if (tab.value === 'login') {
      await login({ phone: phone.value, password: password.value });
    } else {
      await register({ phone: phone.value, password: password.value, captcha: captcha.value });
    }
    router.push('/');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <main class="login-register min-h-screen flex items-center justify-center bg-paper">
    <div class="w-full max-w-md bg-white p-8 rounded shadow">
      <nav class="flex gap-4 border-b border-ink/10 mb-6">
        <button
          v-for="t in (['login','register'] as const)"
          :key="t"
          type="button"
          class="tab px-3 py-2"
          :class="{ 'tab--active border-b-2 border-vermilion text-vermilion': tab === t }"
          @click="tab = t"
        >
          {{ t === 'login' ? '登录' : '注册' }}
        </button>
      </nav>
      <ElForm @submit.prevent="submit">
        <ElFormItem label="手机号">
          <ElInput v-model="phone" name="phone" placeholder="11 位手机号" />
        </ElFormItem>
        <ElFormItem label="密码">
          <ElInput v-model="password" name="password" type="password" show-password />
        </ElFormItem>
        <ElFormItem v-if="tab === 'register'" label="验证码">
          <ElInput v-model="captcha" name="captcha" placeholder="6 位验证码" />
        </ElFormItem>
        <ElButton native-type="submit" type="primary" class="w-full" :loading="submitting">
          {{ tab === 'login' ? '登录' : '注册' }}
        </ElButton>
      </ElForm>
    </div>
  </main>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test LoginRegisterView`
Expected: PASS

### 8.2 LoginView / RegisterView 转为 redirect

- [ ] **Step 1: 重写 LoginView**

`apps/web/src/views/auth/LoginView.vue`：

```vue
<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
onMounted(() => router.replace('/login'));
</script>

<template><div /></template>
```

- [ ] **Step 2: 重写 RegisterView**

`apps/web/src/views/auth/RegisterView.vue`：

```vue
<script setup lang="ts">
import { onMounted } from 'vue';
import { useRouter } from 'vue-router';

const router = useRouter();
onMounted(() => router.replace('/login?tab=register'));
</script>

<template><div /></template>
```

### 8.3 router 重定向

- [ ] **Step 1: 修改 router**

`apps/web/src/router/index.ts`：

```ts
// 把 /login 指向新视图
{
  path: '/login',
  name: 'login',
  meta: { hideFooter: true },
  component: () => import('@/views/auth/LoginRegisterView.vue'),
},
// 删除 /register 路由（或保留为 alias）
{
  path: '/register',
  redirect: '/login?tab=register',
},
```

- [ ] **Step 2: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/views/auth/LoginRegisterView.vue apps/web/src/views/auth/LoginView.vue apps/web/src/views/auth/RegisterView.vue apps/web/src/router/index.ts apps/web/tests/LoginRegisterView.test.ts
git commit -m "refactor(web): 合并登录注册为 LoginRegisterView Tab 视图"
```

---

## Task 9: 学员中心整合（commit 9）

**Files:**
- Create: `apps/web/src/stores/center.ts`
- Create: `apps/web/src/views/me/StudentCenterView.vue`
- Create: `apps/web/src/composables/useCenterTab.ts`
- Delete: `apps/web/src/views/me/MyLearningView.vue`
- Delete: `apps/web/src/views/me/FavoritesView.vue`
- Delete: `apps/web/src/views/me/MyOrdersView.vue`
- Delete: `apps/web/src/views/me/MessagesView.vue`
- Delete: `apps/web/src/views/me/CheckinListView.vue`
- Delete: `apps/web/src/views/me/AccountView.vue`
- Modify: `apps/web/src/router/index.ts`（重定向旧路径）
- Create: `apps/web/tests/StudentCenterView.test.ts`
- Create: `apps/web/tests/center.test.ts`（composable）

### 9.1 useCenterStore

- [ ] **Step 1: 写测试** `apps/web/tests/centerStore.test.ts`：

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import { useCenterStore } from '@/stores/center';

vi.mock('@/api/learner', () => ({ getCenter: vi.fn() }));
import { getCenter } from '@/api/learner';

describe('useCenterStore', () => {
  beforeEach(() => setActivePinia(createPinia()));

  it('loads center data', async () => {
    vi.mocked(getCenter).mockResolvedValue({
      profile: { nickname: 'me' },
      learning: [], favorites: [], orders: [], messages: [],
    });
    const store = useCenterStore();
    await store.load();
    expect(store.data?.profile.nickname).toBe('me');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test centerStore`
Expected: FAIL

- [ ] **Step 3: 写 store**

`apps/web/src/stores/center.ts`：

```ts
import { defineStore } from 'pinia';
import { ref } from 'vue';
import type { LearnerCenter } from '@learn-site/contracts';
import { getCenter } from '@/api/learner';

export const useCenterStore = defineStore('center', () => {
  const data = ref<LearnerCenter | null>(null);
  const loading = ref(false);
  const error = ref<Error | null>(null);

  async function load() {
    loading.value = true;
    error.value = null;
    try {
      data.value = await getCenter();
    } catch (e) {
      error.value = e instanceof Error ? e : new Error(String(e));
    } finally {
      loading.value = false;
    }
  }

  return { data, loading, error, load };
});
```

- [ ] **Step 4: api/learner.ts 补 getCenter**

```ts
import { LearnerCenterSchema } from '@learn-site/contracts';

export async function getCenter(): Promise<LearnerCenter> {
  const { data } = await http.get('/api/learner/v1/me/center');
  return LearnerCenterSchema.parse(data);
}
```

（如后端无 `/me/center` 端点，分端点拉取后前端聚合，标 `// ponytail: backend gap`）

- [ ] **Step 5: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test centerStore`
Expected: PASS

### 9.2 useCenterTab composable

- [ ] **Step 1: 写测试** `apps/web/tests/useCenterTab.test.ts`：

```ts
import { describe, it, expect } from 'vitest';
import { useCenterTab } from '@/composables/useCenterTab';

describe('useCenterTab', () => {
  it('exposes tab keys and current', () => {
    const c = useCenterTab();
    expect(c.tabs).toEqual([
      { key: 'learning', label: '学习中' },
      { key: 'favorites', label: '收藏' },
      { key: 'orders', label: '订单' },
      { key: 'messages', label: '消息' },
      { key: 'checkins', label: '签到' },
      { key: 'account', label: '账户' },
    ]);
    expect(c.current.value).toBe('learning');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test useCenterTab`
Expected: FAIL

- [ ] **Step 3: 写 composable**

`apps/web/src/composables/useCenterTab.ts`：

```ts
import { ref } from 'vue';

export type CenterTabKey =
  | 'learning' | 'favorites' | 'orders'
  | 'messages' | 'checkins' | 'account';

export function useCenterTab() {
  const tabs: ReadonlyArray<{ key: CenterTabKey; label: string }> = [
    { key: 'learning', label: '学习中' },
    { key: 'favorites', label: '收藏' },
    { key: 'orders', label: '订单' },
    { key: 'messages', label: '消息' },
    { key: 'checkins', label: '签到' },
    { key: 'account', label: '账户' },
  ] as const;

  const current = ref<CenterTabKey>('learning');

  return { tabs, current };
}
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test useCenterTab`
Expected: PASS

### 9.3 StudentCenterView

- [ ] **Step 1: 写测试** `apps/web/tests/StudentCenterView.test.ts`：

```ts
import { mount, flushPromises } from '@vue/test-utils';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { setActivePinia, createPinia } from 'pinia';
import StudentCenterView from '@/views/me/StudentCenterView.vue';

vi.mock('@/api/learner', () => ({ getCenter: vi.fn() }));
import { getCenter } from '@/api/learner';

describe('StudentCenterView', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    vi.mocked(getCenter).mockReset();
  });

  it('renders all 6 tabs', async () => {
    vi.mocked(getCenter).mockResolvedValue({
      profile: { nickname: 'me' },
      learning: [], favorites: [], orders: [], messages: [],
    });
    const wrapper = mount(StudentCenterView);
    await flushPromises();
    expect(wrapper.findAll('.learner-tab')).toHaveLength(6);
  });

  it('shows empty favorites tab', async () => {
    vi.mocked(getCenter).mockResolvedValue({
      profile: { nickname: 'me' },
      learning: [], favorites: [], orders: [], messages: [],
    });
    const wrapper = mount(StudentCenterView);
    await flushPromises();
    await wrapper.findAll('.learner-tab')[1].trigger('click');
    expect(wrapper.text()).toContain('还没有收藏');
  });
});
```

- [ ] **Step 2: 跑测试，确认失败**

Run: `pnpm -F @learn-site/web test StudentCenterView`
Expected: FAIL

- [ ] **Step 3: 写视图**

`apps/web/src/views/me/StudentCenterView.vue`：

```vue
<script setup lang="ts">
import { onMounted, computed } from 'vue';
import { storeToRefs } from 'pinia';
import { useCenterStore } from '@/stores/center';
import { useCenterTab } from '@/composables/useCenterTab';
import LearnerTabs from '@/components/LearnerTabs.vue';
import EmptyState from '@/components/EmptyState.vue';
import SkeletonBlock from '@/components/SkeletonBlock.vue';

const store = useCenterStore();
const { data, loading } = storeToRefs(store);
const { tabs, current } = useCenterTab();

onMounted(() => store.load());

const learning = computed(() => data.value?.learning ?? []);
const favorites = computed(() => data.value?.favorites ?? []);
const orders = computed(() => data.value?.orders ?? []);
const messages = computed(() => data.value?.messages ?? []);
</script>

<template>
  <main class="student-center max-w-screen-xl mx-auto px-6 py-8">
    <h1 class="text-2xl font-serif mb-6">学员中心</h1>
    <SkeletonBlock v-if="loading" :count="6" />
    <template v-else-if="data">
      <LearnerTabs v-model="current" :tabs="tabs" />
      <section v-if="current === 'learning'" class="mt-6">
        <EmptyState v-if="learning.length === 0" title="还没有学习记录" />
        <ul v-else class="space-y-3">
          <li v-for="c in learning" :key="c.id" class="border border-ink/10 rounded p-4">
            <h3>{{ c.title }}</h3>
            <p class="text-sm text-ink/60">进度 {{ c.progressPercent }}%</p>
          </li>
        </ul>
      </section>
      <section v-else-if="current === 'favorites'" class="mt-6">
        <EmptyState v-if="favorites.length === 0" title="还没有收藏" description="去课程页点击收藏" />
        <ul v-else class="grid grid-cols-3 gap-4">
          <li v-for="c in favorites" :key="c.id" class="border border-ink/10 rounded">
            <img :src="c.coverImage" :alt="c.title" class="w-full h-32 object-cover" />
            <h3 class="p-3 text-sm">{{ c.title }}</h3>
          </li>
        </ul>
      </section>
      <section v-else-if="current === 'orders'" class="mt-6">
        <EmptyState v-if="orders.length === 0" title="还没有订单" />
        <ul v-else class="space-y-3">
          <li v-for="o in orders" :key="o.id" class="border border-ink/10 rounded p-4">
            <h3>{{ o.courseTitle }}</h3>
            <p class="text-sm text-ink/60">¥{{ (o.priceCents / 100).toFixed(2) }} · {{ o.status }}</p>
          </li>
        </ul>
      </section>
      <section v-else-if="current === 'messages'" class="mt-6">
        <EmptyState v-if="messages.length === 0" title="没有消息" />
        <ul v-else class="space-y-3">
          <li v-for="m in messages" :key="m.id" class="border border-ink/10 rounded p-4">
            <h3>{{ m.title }}</h3>
            <p class="text-sm text-ink/60">{{ m.body }}</p>
          </li>
        </ul>
      </section>
      <section v-else-if="current === 'checkins'" class="mt-6">
        <p>签到功能集成中（参考 CheckinPlanEditor.vue）</p>
      </section>
      <section v-else-if="current === 'account'" class="mt-6">
        <p>{{ data.profile.nickname }}</p>
      </section>
    </template>
  </main>
</template>
```

- [ ] **Step 4: 跑测试，确认通过**

Run: `pnpm -F @learn-site/web test StudentCenterView`
Expected: PASS

### 9.4 删除旧 me/* 视图 + router 重定向

- [ ] **Step 1: router 重定向**

`apps/web/src/router/index.ts`：

```ts
// 删除以下路由：
// - learning: /me/learning
// - favorites: /me/favorites
// - orders: /me/orders
// - messages: /me/messages
// - checkins: /me/checkins
// - account: /me/account
// 替换为：
{
  path: '/me/:tab(learning|favorites|orders|messages|checkins|account)?',
  name: 'center',
  beforeEnter: requireLearnerAuth,
  component: () => import('@/views/me/StudentCenterView.vue'),
},
// 保留 /me 路径作为默认
{
  path: '/me',
  redirect: '/me/learning',
},
```

- [ ] **Step 2: 删除旧文件**

```bash
rm apps/web/src/views/me/MyLearningView.vue
rm apps/web/src/views/me/FavoritesView.vue
rm apps/web/src/views/me/MyOrdersView.vue
rm apps/web/src/views/me/MessagesView.vue
rm apps/web/src/views/me/CheckinListView.vue
rm apps/web/src/views/me/AccountView.vue
```

- [ ] **Step 3: 删除或更新对应测试**

```bash
rm apps/web/tests/MyLearningView.test.ts
rm apps/web/tests/FavoritesView.test.ts
rm apps/web/tests/MyOrdersView.test.ts
rm apps/web/tests/MessagesView.test.ts
rm apps/web/tests/CheckinListView.test.ts
rm apps/web/tests/AccountView.test.ts
```

（或迁移到新视图测试）

- [ ] **Step 4: 跑测试 + 质量门 + commit**

```bash
pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web test && pnpm -F @learn-site/web build
```

```bash
git add apps/web/src/stores/center.ts apps/web/src/views/me/StudentCenterView.vue apps/web/src/composables/useCenterTab.ts apps/web/src/router/index.ts apps/web/src/api/learner.ts apps/web/tests/
git commit -m "refactor(web): 学员中心 6-Tab 整合为 StudentCenterView"
```

---

## Task 10: 教训汇总（commit 10）

**Files:**
- Modify: `tasks/lessons.md`

### 10.1 收集 backend gap

- [ ] **Step 1: grep 所有 `// ponytail: backend gap`**

Run: `git grep "ponytail: backend gap"`

记录每个出现的：Figma 节点、字段名、占位策略。

### 10.2 追加到 lessons.md

- [ ] **Step 1: 追加段落到 `tasks/lessons.md`**

格式：

```markdown
## 2026-08-31 Figma 全覆盖重写 backend gap 汇总

| Figma 节点 | 字段 | 后端状态 | 前端占位策略 |
| --- | --- | --- | --- |
| 首页 Hero | `coverImage` 高清大图 | 缺失 1920x720 | 用 1280x720 缩放 |
| 学习地图详情 | `map.publishedAt` | 缺失 | 默认"近期" |
| 课程详情 | `instructorAvatarUrl` | 缺失 | 用首字母占位 |
| ... | ... | ... | ... |

### 复盘教训

- Figma 字段覆盖率约 X/字段总数 = ~70%；剩余 30% 用占位不影响主流程。
- contracts 优先定义能消除 store 类型断言；建议后续按页面 pull 端点聚合。
- 删除 me/* 视图后 router 重定向 + alias 保留是必修。
```

### 10.3 提交

```bash
git add tasks/lessons.md
git commit -m "docs(lessons): 汇总 Figma 重写 backend gap 与复盘"
```

---

## 自审清单

- [x] Spec coverage：11 桌面页 → Task 1-9 全部覆盖；contracts → Task 1.5；测试 → 各 Task；commit 节奏 → 10 个 commit
- [x] Placeholder scan：grep 后无 TBD/TODO/"implement later"
- [x] Type consistency：`MapListItem`/`MapDetail`/`CourseDetail`/`Lesson`/`CheckoutSummary`/`LearnerCenter` 在 contracts 中定义，后续 Task 引用一致
- [x] Ambiguity：每个 store 的字段名/方法名跨 Task 一致；`loading/error/data` 三态统一

---

## 执行方式

两个选项：

1. **Subagent-Driven (recommended)** - 每 Task 派一个 subagent 执行，两阶段 review，迭代快
2. **Inline Execution** - 在当前会话按 Task 顺序执行，到 commit 节点停下来确认

请告诉我走哪个方式。