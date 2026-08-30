# 学习端 Figma 全覆盖重写实施计划 v2

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 按 Figma 设计稿「拾阶学社」11 个桌面画板全覆盖重写 `apps/web`（Vue 3 SPA），**复用现有 33+ `fetch*` 与 27 个 DTO 文件**，仅扩展 `HomePayload.recommended_maps` 1 处，11 个独立可回滚 commit。

**Architecture:** 单页应用重写，不引入新依赖。Store 仅薄包装 API + 状态；派生数据（如 STREAK / 热力图）放 store getter；新组件 4 个（PageHeader / EmptyState / SkeletonBlock / LearnerTabs）+ 1 composable（useMapLearningState）。

**Tech Stack:** Vue 3.5 + Vite 5 + TypeScript strict + Element Plus 2.8.4 + Tailwind 3.4 + Pinia 2.2 + Zod 3.23 + vitest 2.1（happy-dom）。`@learn-site/contracts` 别名指向 `packages/contracts/src`。

**Spec:** `docs/superpowers/specs/2026-08-31-figma-web-redesign-design.md`（已修正为现有 DTO 复用 + 1 处扩展）

**Memory 引用（按需查阅）：**
- `figma-pickup-academy` — 11 个 Figma frame id 速查
- `figma-page-summary` — 11 页布局要点 + backend gap 候选
- `dto-inventory` — 28 个 DTO 形状速查
- `api-inventory` — 33+ fetch* 与视图调用映射

**质量门（每 commit 前必须全过）：**
```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```

---

## 文件结构与职责

```
apps/web/src/
├── components/                                  # 新增 4 个 + 现有 10 个
│   ├── PageHeader.vue                           # 新增：二级页顶栏（logo/搜索/用户入口）
│   ├── EmptyState.vue                           # 新增：图+文+CTA 空状态
│   ├── SkeletonBlock.vue                        # 新增：骨架屏
│   └── LearnerTabs.vue                          # 新增：学员中心 Tab 容器
├── composables/
│   ├── useLearnerSession.ts                     # 现有：登录态（不动）
│   └── useMapLearningState.ts                   # 新增：阶段状态派生
├── stores/
│   ├── home.ts                                  # 现有：扩 recommendedMaps 字段
│   ├── mapList.ts                               # 新增：fetchLearningMaps 薄包装
│   ├── mapDetail.ts                             # 新增：fetchLearningMap + startLearningMap
│   ├── courseDetail.ts                          # 新增：fetchCourseDetail + reviews
│   ├── lesson.ts                                # 新增：fetchLesson + progress 上报
│   ├── checkout.ts                              # 新增：createCourseOrder + 轮询
│   └── center.ts                                # 新增：聚合 6 Tab + STREAK/heatmap getter
├── views/
│   ├── home/HomeView.vue                        # 重写：v-if loading/empty/data
│   ├── maps/MapListView.vue                     # 重写：三列布局
│   ├── maps/MapDetailView.vue                   # 重写：阶段 + 课程
│   ├── catalog/CourseDetailView.vue             # 重写：双列 + Tab
│   ├── learn/LessonView.vue                     # 重写：三栏
│   ├── checkout/CheckoutView.vue                # 重写：双列订单 + 支付
│   ├── auth/LoginRegisterView.vue               # 新增：合并登录/注册
│   ├── auth/LoginView.vue                       # 薄 redirect → /login
│   ├── auth/RegisterView.vue                    # 薄 redirect → /login
│   ├── me/StudentCenterView.vue                 # 新增：聚合 6 Tab
│   └── me/{MyLearningView,FavoritesView,MyOrdersView,MessagesView,CheckinListView,AccountView}.vue
│                                                 # 改：薄 redirect
├── router/index.ts                              # 改：me/* 路径聚合 + login 指向新视图
└── api/learner.ts                               # 不动（33+ fetch 已存在）

packages/contracts/src/home.ts                   # 扩 1 字段：recommended_maps
apps/api/app/controller/learner/HomeController.php  # 加 1 字段查询
apps/web/tests/                                  # 每个 commit 配套 vitest
```

---

### Commit 1: 脚手架 + contracts 扩展

**Files:**
- Create: `apps/web/src/components/PageHeader.vue`
- Create: `apps/web/src/components/EmptyState.vue`
- Create: `apps/web/src/components/SkeletonBlock.vue`
- Create: `apps/web/src/components/LearnerTabs.vue`
- Create: `apps/web/src/composables/useMapLearningState.ts`
- Modify: `packages/contracts/src/home.ts`
- Modify: `apps/api/app/controller/learner/HomeController.php`
- Create: `apps/web/tests/components/{PageHeader,EmptyState,SkeletonBlock,LearnerTabs}.test.ts`
- Create: `apps/web/tests/composables/useMapLearningState.test.ts`
- Create: `apps/web/tests/contracts/home.test.ts`

- [ ] **Step 1: 写 HomePayload 扩展失败的类型测试**

```ts
// apps/web/tests/contracts/home.test.ts
import { HomePayload } from '@learn-site/contracts'

describe('HomePayload schema', () => {
  it('accepts recommended_maps field', () => {
    const result = HomePayload.parse({
      categories: [],
      site_intro: { title: '', subtitle: '', body_html: '', contact_email: '', updated_at: null },
      recent_courses: [],
      banners: [],
      recommended_maps: [],
    })
    expect(result.recommended_maps).toEqual([])
  })
})
```

- [ ] **Step 2: 跑测试确认失败**

```bash
cd apps/web && pnpm test contracts/home.test.ts
```
Expected: FAIL（HomePayload 还没 recommended_maps）

- [ ] **Step 3: 扩 HomePayload**

修改 `packages/contracts/src/home.ts`：在已有 `HomePayload` 末尾追加：
```ts
import { z } from 'zod'
import { CourseListItemDTO } from './catalog.js'
import { SiteIntro } from './site.js'
import { BannerPublicDTO } from './banner.js'
import { MapSummaryDTO, MapEnrollmentDTO } from './learningMap.js'

export const RecommendedMapDTO = MapSummaryDTO.extend({
  enrollment: MapEnrollmentDTO.nullable(),
})
export type RecommendedMapDTO = z.infer<typeof RecommendedMapDTO>

// 修改 HomePayload：加 recommended_maps
export const HomePayload = z.object({
  categories: z.array(CategoryNode),
  site_intro: SiteIntro,
  recent_courses: z.array(CourseListItemDTO),
  banners: z.array(BannerPublicDTO).default([]),
  recommended_maps: z.array(RecommendedMapDTO).default([]),
})
export type HomePayload = {
  categories: CategoryNode[]
  site_intro: z.infer<typeof SiteIntro>
  recent_courses: z.infer<typeof CourseListItemDTO>[]
  banners: z.infer<typeof BannerPublicDTO>[]
  recommended_maps: RecommendedMapDTO[]
}
```

- [ ] **Step 4: 后端 HomeController 返回 recommended_maps**

修改 `apps/api/app/controller/learner/HomeController.php`：在 `home()` 方法返回数组末尾追加：
```php
// 取前 3 条 published map
$recommendedMaps = \app\model\LearningMap::where('status', 'published')
    ->orderByDesc('created_at')
    ->limit(3)
    ->get()
    ->map(function ($m) {
        return [
            'id' => $m->id,
            'department_id' => $m->department_id ?? 0,
            'title' => $m->title,
            'summary' => $m->summary ?? '',
            'cover_url' => $m->cover_url ?? '',
            'estimated_hours' => $m->estimated_hours ?? 0,
            'stage_count' => $m->stage_count ?? 0,
            'enrollment' => null, // HomeController 不带 enrollment 详情
        ];
    })->toArray();

return [
    'categories' => ...,
    'site_intro' => ...,
    'recent_courses' => ...,
    'banners' => ...,
    'recommended_maps' => $recommendedMaps,
];
```

- [ ] **Step 5: 跑测试确认通过**

```bash
cd apps/web && pnpm test contracts/home.test.ts
```
Expected: PASS

- [ ] **Step 6: 写 4 个组件的渲染测试 + useMapLearningState composable 测试**

```ts
// apps/web/tests/components/EmptyState.test.ts
// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import EmptyState from '@/components/EmptyState.vue'
import { describe, expect, it } from 'vitest'

describe('EmptyState', () => {
  it('renders illustration placeholder, headline, sub, CTA', () => {
    const w = mount(EmptyState, {
      props: { headline: '暂无数据', sub: '探索更多', ctaText: '去看看', ctaHref: '/maps' },
    })
    expect(w.text()).toContain('暂无数据')
    expect(w.text()).toContain('去看看')
  })
})
```

```ts
// apps/web/tests/components/SkeletonBlock.test.ts
// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { describe, expect, it } from 'vitest'

describe('SkeletonBlock', () => {
  it('renders 3 rows by default', () => {
    const w = mount(SkeletonBlock)
    expect(w.findAll('[data-testid="skeleton-row"]')).toHaveLength(3)
  })
  it('respects rows prop', () => {
    const w = mount(SkeletonBlock, { props: { rows: 6 } })
    expect(w.findAll('[data-testid="skeleton-row"]')).toHaveLength(6)
  })
})
```

```ts
// apps/web/tests/components/PageHeader.test.ts
// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import PageHeader from '@/components/PageHeader.vue'
import { useLearnerSession } from '@/composables/useLearnerSession'
import { describe, expect, it, beforeEach } from 'vitest'

describe('PageHeader', () => {
  beforeEach(() => setActivePinia(createPinia()))
  it('renders logo, search placeholder, anonymous user entry', () => {
    const w = mount(PageHeader)
    expect(w.text()).toContain('拾阶学社')
    expect(w.text()).toContain('搜索')
    expect(w.text()).toContain('登录')
  })
})
```

```ts
// apps/web/tests/components/LearnerTabs.test.ts
// @vitest-environment happy-dom
import { mount } from '@vue/test-utils'
import LearnerTabs from '@/components/LearnerTabs.vue'
import { describe, expect, it } from 'vitest'

describe('LearnerTabs', () => {
  const tabs = [
    { key: 'a', label: 'Tab A' },
    { key: 'b', label: 'Tab B' },
  ]
  it('switches active tab on click', async () => {
    const w = mount(LearnerTabs, {
      props: { tabs, modelValue: 'a' },
      slots: { a: '<div>content A</div>', b: '<div>content B</div>' },
    })
    expect(w.text()).toContain('content A')
    await w.get('[data-tab="b"]').trigger('click')
    expect(w.emitted('update:modelValue')?.[0]).toEqual(['b'])
  })
})
```

```ts
// apps/web/tests/composables/useMapLearningState.test.ts
import { ref } from 'vue'
import { useMapLearningState } from '@/composables/useMapLearningState'
import type { LearnerMapDetailDTO } from '@learn-site/contracts'
import { describe, expect, it } from 'vitest'

function makeMap(stages: Array<{ id: number; courses: Array<{ id: number; completed: boolean }> }>): LearnerMapDetailDTO {
  return {
    id: 1,
    department_id: 1,
    title: 'test',
    summary: '',
    cover_url: '',
    estimated_hours: 0,
    stage_count: stages.length,
    enrollment: null,
    next_step: null,
    stages: stages.map((s, idx) => ({
      id: s.id,
      title: `stage-${idx}`,
      summary: '',
      order: idx,
      unlock_rule: 'sequential',
      courses: s.courses.map(c => ({
        id: c.id,
        title: 'c',
        summary: '',
        cover_url: '',
        duration_hours: 1,
        tag: '',
        progress_percent: c.completed ? 100 : 0,
        lectures_count: 0,
        completed: c.completed,
        available: true,
        viewer_authorized: false,
      })),
    })),
  }
}

describe('useMapLearningState', () => {
  it('returns [] when map is null', () => {
    const m = ref<LearnerMapDetailDTO | null>(null)
    const { stageStates } = useMapLearningState(m)
    expect(stageStates.value).toEqual([])
  })

  it('returns [completed, completed, active] when first two stages fully completed', () => {
    const m = ref(makeMap([
      { id: 1, courses: [{ id: 1, completed: true }] },
      { id: 2, courses: [{ id: 2, completed: true }] },
      { id: 3, courses: [{ id: 3, completed: false }] },
    ]))
    const { stageStates } = useMapLearningState(m)
    expect(stageStates.value).toEqual(['completed', 'completed', 'active'])
  })

  it('returns [active, active, active] when nothing completed', () => {
    const m = ref(makeMap([
      { id: 1, courses: [{ id: 1, completed: false }] },
      { id: 2, courses: [{ id: 2, completed: false }] },
      { id: 3, courses: [{ id: 3, completed: false }] },
    ]))
    const { stageStates } = useMapLearningState(m)
    expect(stageStates.value).toEqual(['active', 'active', 'active'])
  })
})
```

- [ ] **Step 7: 跑测试确认 4 组件 + composable 测试全部 FAIL**

```bash
cd apps/web && pnpm test components composables
```
Expected: 全部 FAIL（组件/composable 还没实现）

- [ ] **Step 8: 实现 4 个新组件**

`apps/web/src/components/EmptyState.vue`：
```vue
<script setup lang="ts">
import { useRouter } from 'vue-router'
const props = defineProps<{
  headline: string
  sub?: string
  ctaText?: string
  ctaHref?: string
}>()
const router = useRouter()
function go() { if (props.ctaHref) router.push(props.ctaHref) }
</script>
<template>
  <div class="empty-state" data-testid="empty-state">
    <div class="empty-state__art" aria-hidden="true" />
    <h3 class="empty-state__headline">{{ headline }}</h3>
    <p v-if="sub" class="empty-state__sub">{{ sub }}</p>
    <el-button v-if="ctaText" type="primary" @click="go">{{ ctaText }}</el-button>
  </div>
</template>
<style scoped>
.empty-state { display:flex; flex-direction:column; align-items:center; padding:48px 24px; gap:12px; }
.empty-state__art { width:160px; height:120px; border:1px dashed var(--ink,#333); border-radius:8px; }
.empty-state__headline { font-size:18px; color:var(--ink); margin:0; }
.empty-state__sub { color:var(--ink-muted,#666); font-size:14px; margin:0; }
</style>
```

`apps/web/src/components/SkeletonBlock.vue`：
```vue
<script setup lang="ts">
withDefaults(defineProps<{ rows?: number }>(), { rows: 3 })
</script>
<template>
  <div class="skeleton-block" data-testid="skeleton-block">
    <div v-for="i in rows" :key="i" data-testid="skeleton-row" class="skeleton-block__row">
      <el-skeleton :rows="1" animated />
    </div>
  </div>
</template>
<style scoped>
.skeleton-block { display:flex; flex-direction:column; gap:16px; }
.skeleton-block__row { padding:12px; border:1px solid var(--paper,#f5f5f5); border-radius:6px; }
</style>
```

`apps/web/src/components/PageHeader.vue`：
```vue
<script setup lang="ts">
import { useRouter } from 'vue-router'
const router = useRouter()
function goLogin() { router.push('/login') }
function goHome() { router.push('/') }
</script>
<template>
  <header class="page-header">
    <div class="page-header__logo" @click="goHome" role="button">拾阶学社</div>
    <el-input placeholder="搜索课程 / 地图 / 导师" class="page-header__search" />
    <nav class="page-header__nav">
      <router-link to="/">首页</router-link>
      <router-link to="/maps">学习地图</router-link>
      <router-link to="/me">我的</router-link>
      <el-button text @click="goLogin">登录</el-button>
    </nav>
  </header>
</template>
<style scoped>
.page-header { display:flex; align-items:center; gap:24px; padding:12px 32px; border-bottom:1px solid var(--paper,#eee); background:#fff; }
.page-header__logo { font-size:20px; font-weight:bold; cursor:pointer; color:var(--vermilion,#a83232); }
.page-header__search { flex:1; max-width:480px; }
.page-header__nav { display:flex; gap:16px; align-items:center; }
</style>
```

`apps/web/src/components/LearnerTabs.vue`：
```vue
<script setup lang="ts">
const props = defineProps<{
  tabs: Array<{ key: string; label: string }>
  modelValue: string
}>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>
<template>
  <el-tabs :model-value="props.modelValue" type="card" @update:model-value="$emit('update:modelValue', $event)">
    <el-tab-pane
      v-for="t in tabs"
      :key="t.key"
      :name="t.key"
    >
      <template #label>
        <span :data-tab="t.key">{{ t.label }}</span>
      </template>
      <slot :name="t.key" />
    </el-tab-pane>
  </el-tabs>
</template>
```

- [ ] **Step 9: 实现 useMapLearningState composable**

```ts
// apps/web/src/composables/useMapLearningState.ts
import { computed, type Ref } from 'vue'
import type { LearnerMapDetailDTO } from '@learn-site/contracts'

export type StageState = 'completed' | 'active' | 'locked'

export function useMapLearningState(map: Ref<LearnerMapDetailDTO | null>) {
  const stageStates = computed<StageState[]>(() => {
    if (!map.value) return []
    const stages = map.value.stages
    const completedCount = stages.filter(s => s.courses.length > 0 && s.courses.every(c => c.completed)).length
    return stages.map((s, idx) => {
      const allCompleted = s.courses.length > 0 && s.courses.every(c => c.completed)
      if (allCompleted) return 'completed' as const
      if (idx === completedCount) return 'active' as const
      return 'locked' as const
    })
  })

  return { stageStates }
}
```

- [ ] **Step 10: 跑全部新增测试确认 PASS**

```bash
cd apps/web && pnpm test
```
Expected: ALL PASS

- [ ] **Step 11: 跑四项质量门**

```bash
pnpm -F @learn-site/web typecheck
pnpm -F @learn-site/web lint
pnpm -F @learn-site/web test
pnpm -F @learn-site/web build
```
Expected: 全部通过

- [ ] **Step 12: Commit**

```bash
git add packages/contracts/src/home.ts \
        apps/api/app/controller/learner/HomeController.php \
        apps/web/src/components/{PageHeader,EmptyState,SkeletonBlock,LearnerTabs}.vue \
        apps/web/src/composables/useMapLearningState.ts \
        apps/web/tests/components \
        apps/web/tests/composables \
        apps/web/tests/contracts

git commit -m "feat(web): 新增 4 个通用组件 + useMapLearningState + HomePayload 扩 recommended_maps

Figma 重写脚手架 commit (1/11)：
- 4 个新组件：PageHeader / EmptyState / SkeletonBlock / LearnerTabs
- 1 个 composable：useMapLearningState（阶段状态派生）
- HomePayload 扩 recommended_maps 字段（11 页 Figma 唯一新增 DTO 字段）
- HomeController 返回前 3 条 published map
- 配套 6 个 vitest 测试，全部通过"
```

---

### Commit 2: 首页 HomeView 三态

**Files:**
- Modify: `apps/web/src/views/home/HomeView.vue`
- Modify: `apps/web/src/stores/home.ts`
- Modify: `apps/web/tests/HomeStore.test.ts`
- Modify: `apps/web/tests/HomeView.test.ts`

- [ ] **Step 1: 写 HomeStore 扩展测试**

在 `apps/web/tests/HomeStore.test.ts` 追加：
```ts
it('stores recommended_maps from home payload', async () => {
  const fakeMap = {
    id: 1, department_id: 1, title: '诸子百家', summary: '',
    cover_url: '', estimated_hours: 0, stage_count: 0, enrollment: null,
  }
  learnerApi.fetchHome.mockResolvedValueOnce({
    categories: [],
    site_intro: blankIntro,
    recent_courses: [],
    banners: [],
    recommended_maps: [fakeMap],
  })
  const store = useHomeStore()
  await store.load()
  expect(store.recommendedMaps).toHaveLength(1)
  expect(store.recommendedMaps[0].title).toBe('诸子百家')
})
```

- [ ] **Step 2: 写 HomeView 三态测试**

在 `apps/web/tests/HomeView.test.ts` 追加：
```ts
it('renders HomeBannerCarousel + 推荐学习地图 when data loaded', async () => {
  learnerApi.fetchHome.mockResolvedValueOnce({
    categories: [category],
    recent_courses: [course],
    banners: [],
    site_intro: blankIntro,
    recommended_maps: [{ id: 1, department_id: 1, title: '诸子百家', summary: '', cover_url: '', estimated_hours: 0, stage_count: 0, enrollment: null }],
  })
  const w = mount(HomeView)
  await flushPromises()
  expect(w.find('[data-testid="home-banner-carousel"]').exists()).toBe(true)
  expect(w.text()).toContain('推荐学习地图')
  expect(w.text()).toContain('诸子百家')
})

it('renders 6 SkeletonBlock rows when loading', async () => {
  learnerApi.fetchHome.mockReturnValueOnce(new Promise(() => {}))
  const w = mount(HomeView)
  expect(w.findAll('[data-testid="skeleton-block"]').length).toBeGreaterThanOrEqual(6)
})

it('renders EmptyState when category courses empty', async () => {
  learnerApi.fetchHome.mockResolvedValueOnce({
    categories: [{ id: 99, name: 'empty-cat', children: [] }],
    recent_courses: [],
    banners: [],
    site_intro: blankIntro,
    recommended_maps: [],
  })
  learnerApi.fetchCategoryCourses.mockResolvedValueOnce({
    category: { id: 99, name: 'empty-cat', path: [] },
    list: { items: [], total: 0, page: 1, limit: 100 },
  })
  const w = mount(HomeView)
  await flushPromises()
  await w.find('.el-tree-node__content').trigger('click')
  await flushPromises()
  expect(w.find('[data-testid="empty-state"]').exists()).toBe(true)
})
```

- [ ] **Step 3: 跑测试确认 FAIL**

```bash
cd apps/web && pnpm test HomeView HomeStore
```

- [ ] **Step 4: 扩 HomeStore**

`apps/web/src/stores/home.ts` 增加：
```ts
import type { RecommendedMapDTO } from '@learn-site/contracts'

const recommendedMaps = ref<RecommendedMapDTO[]>([])
// load() 内赋值：
recommendedMaps.value = payload.recommended_maps ?? []
// 暴露：
return { ..., recommendedMaps }
```

- [ ] **Step 5: 重写 HomeView**

`apps/web/src/views/home/HomeView.vue` 改为三态：
```vue
<script setup lang="ts">
import { computed, ref, onMounted } from 'vue'
import { useHomeStore } from '@/stores/home'
import HomeBannerCarousel from '@/components/HomeBannerCarousel.vue'
import EmptyState from '@/components/EmptyState.vue'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import { fetchCategoryCourses } from '@/api/learner'

const store = useHomeStore()
const selectedCategory = ref<number | null>(null)
const courses = ref<any[]>([])

onMounted(async () => { await store.load() })

watch(() => store.categories, async (cats) => {
  if (cats.length && selectedCategory.value === null) {
    selectedCategory.value = cats[0].id
    await loadCourses()
  }
}, { immediate: true })

async function loadCourses() {
  if (selectedCategory.value === null) return
  const res = await fetchCategoryCourses(selectedCategory.value, 1, 100)
  courses.value = res.list.items
}

const showLoading = computed(() => store.loading && !store.loaded)
const showEmpty = computed(() => store.loaded && store.recentCourses.length === 0)
</script>

<template>
  <main class="home">
    <HomeBannerCarousel :banners="store.banners" data-testid="home-banner-carousel" />

    <section v-if="showLoading" class="home__loading">
      <SkeletonBlock :rows="6" />
    </section>

    <section v-else-if="showEmpty" class="home__empty">
      <EmptyState
        headline="还没有开始学习"
        sub="从学习地图探索一条进阶路径"
        cta-text="去看看"
        cta-href="/maps"
      />
    </section>

    <section v-else class="home__data">
      <h2>推荐学习地图</h2>
      <div class="home__map-grid">
        <article v-for="m in store.recommendedMaps" :key="m.id" class="home__map-card">
          <img :src="m.cover_url" :alt="m.title" />
          <h3>{{ m.title }}</h3>
          <p>{{ m.stage_count }} 阶段 · 约 {{ m.estimated_hours }} 小时</p>
          <router-link :to="`/maps/${m.id}`">开始探索 →</router-link>
        </article>
      </div>

      <h2>分类课程</h2>
      <div class="home__catalog">
        <el-tree :data="store.categories" :props="{ label: 'name', children: 'children' }" @node-click="(n: any) => { selectedCategory = n.id; loadCourses() }" />
        <div class="home__courses">
          <article v-for="c in courses" :key="c.id">
            <h4>{{ c.title }}</h4>
            <p>{{ c.summary }}</p>
          </article>
        </div>
      </div>
    </section>
  </main>
</template>

<style scoped>
.home { padding:32px; max-width:1280px; margin:0 auto; }
.home__map-grid { display:grid; grid-template-columns:repeat(3,1fr); gap:24px; }
.home__map-card { border:1px solid var(--paper,#eee); border-radius:8px; padding:16px; }
.home__catalog { display:grid; grid-template-columns:240px 1fr; gap:24px; }
.home__courses { display:grid; grid-template-columns:repeat(3,1fr); gap:16px; }
</style>
```

- [ ] **Step 6: 跑测试确认 PASS**

```bash
cd apps/web && pnpm test HomeView HomeStore
```

- [ ] **Step 7: 跑四项质量门**

- [ ] **Step 8: Commit**

```bash
git add apps/web/src/views/home apps/web/src/stores/home.ts apps/web/tests/HomeView.test.ts apps/web/tests/HomeStore.test.ts
git commit -m "feat(web): 首页 HomeView 三态重写（数据/加载/空）+ 推荐地图 3 卡 (2/11)"
```

---

### Commit 3: 学习地图列表 MapListView

**Files:**
- Create: `apps/web/src/stores/mapList.ts`
- Modify: `apps/web/src/views/maps/MapListView.vue`
- Create: `apps/web/tests/stores/MapListStore.test.ts`
- Modify: `apps/web/tests/MapListView.test.ts`（若已有）

- [ ] **Step 1: 写 MapListStore 测试**

```ts
// apps/web/tests/stores/MapListStore.test.ts
// @vitest-environment happy-dom
import { setActivePinia, createPinia } from 'pinia'
import { useMapListStore } from '@/stores/mapList'
import * as api from '@/api/learner'

vi.mock('@/api/learner')

describe('useMapListStore', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('load() fetches learning maps', async () => {
    vi.mocked(api.fetchLearningMaps).mockResolvedValueOnce({
      items: [{
        id: 1, department_id: 1, title: '诸子百家', summary: '',
        cover_url: '', estimated_hours: 0, stage_count: 0, enrollment: null,
      }],
      total: 1, page: 1, limit: 20,
    })
    const store = useMapListStore()
    await store.load()
    expect(store.items).toHaveLength(1)
    expect(store.loading).toBe(false)
    expect(store.error).toBe(false)
  })

  it('load() dedupes concurrent calls', async () => {
    let resolveFn!: (v: any) => void
    vi.mocked(api.fetchLearningMaps).mockReturnValueOnce(new Promise(r => { resolveFn = r }))
    const store = useMapListStore()
    const p1 = store.load()
    const p2 = store.load()
    resolveFn({ items: [], total: 0, page: 1, limit: 20 })
    await Promise.all([p1, p2])
    expect(api.fetchLearningMaps).toHaveBeenCalledTimes(1)
  })
})
```

- [ ] **Step 2: 实现 store**

```ts
// apps/web/src/stores/mapList.ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { LearnerMapListDTO, MapSummaryDTO, MapEnrollmentDTO } from '@learn-site/contracts'
import { fetchLearningMaps } from '@/api/learner'

export type RecommendedMapItem = MapSummaryDTO & { enrollment: MapEnrollmentDTO | null }

export const useMapListStore = defineStore('mapList', () => {
  const items = ref<RecommendedMapItem[]>([])
  const loading = ref(false)
  const loaded = ref(false)
  const error = ref(false)
  let inflight: Promise<void> | null = null

  function load(): Promise<void> {
    if (loaded.value) return Promise.resolve()
    if (inflight) return inflight
    loading.value = true
    error.value = false
    inflight = (async () => {
      try {
        const res: LearnerMapListDTO = await fetchLearningMaps()
        items.value = res.items
        loaded.value = true
      } catch { error.value = true } finally {
        loading.value = false
        inflight = null
      }
    })()
    return inflight
  }
  return { items, loading, error, loaded, load }
})
```

- [ ] **Step 3: 写 MapListView 测试**

若 `apps/web/tests/MapListView.test.ts` 不存在则创建；若存在则追加：
```ts
// apps/web/tests/MapListView.test.ts
// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils'
import { setActivePinia, createPinia } from 'pinia'
import MapListView from '@/views/maps/MapListView.vue'
import * as api from '@/api/learner'

vi.mock('@/api/learner')

describe('MapListView', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('renders three-column layout with map list', async () => {
    vi.mocked(api.fetchLearningMaps).mockResolvedValueOnce({
      items: [{
        id: 1, department_id: 1, title: '诸子百家', summary: '人文',
        cover_url: '', estimated_hours: 12, stage_count: 3, enrollment: null,
      }],
      total: 1, page: 1, limit: 20,
    })
    const w = mount(MapListView)
    await flushPromises()
    expect(w.text()).toContain('诸子百家')
    expect(w.text()).toContain('人文')
    expect(w.text()).toContain('开始探索')
  })
})
```

- [ ] **Step 4: 实现 MapListView**

```vue
<!-- apps/web/src/views/maps/MapListView.vue -->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useMapListStore } from '@/stores/mapList'
import SkeletonBlock from '@/components/SkeletonBlock.vue'
import EmptyState from '@/components/EmptyState.vue'

const store = useMapListStore()
const selected = ref<number | null>(null)

onMounted(async () => { await store.load() })

const selectedItem = computed(() => store.items.find(m => m.id === selected.value) ?? null)

// ponytail: backend gap: map.category/preview_graph → 前端占位
function categoryLabel(_m: any) { return '人文' }
function previewGraph(m: any): string[] {
  return Array.from({ length: Math.min(m.stage_count, 4) }, (_, i) => `阶段 ${i + 1}`)
}
</script>

<template>
  <main class="map-list">
    <aside class="map-list__sidebar">
      <h3>分类</h3>
      <ul>
        <li><router-link to="/maps">全部</router-link></li>
        <li><a>人文</a></li>
        <li><a>社科</a></li>
        <li><a>艺术</a></li>
        <li><a>科技</a></li>
        <li><a>经管</a></li>
      </ul>
    </aside>

    <section class="map-list__main">
      <SkeletonBlock v-if="store.loading" :rows="4" />
      <EmptyState v-else-if="store.items.length === 0" headline="还没有学习地图" sub="敬请期待" />
      <ul v-else class="map-list__items">
        <li
          v-for="m in store.items"
          :key="m.id"
          :data-map-id="m.id"
          @click="selected = m.id"
          :class="{ 'is-selected': selected === m.id }"
        >
          <span class="badge">{{ categoryLabel(m) }}</span>
          <h4>{{ m.title }}</h4>
          <p>{{ m.stage_count }} 节点 · 约 {{ m.estimated_hours }} 小时</p>
          <router-link :to="`/maps/${m.id}`">开始探索 →</router-link>
        </li>
      </ul>
    </section>

    <aside class="map-list__preview">
      <template v-if="selectedItem">
        <h3>{{ selectedItem.title }}</h3>
        <p>{{ selectedItem.summary }}</p>
        <ol>
          <li v-for="(step, idx) in previewGraph(selectedItem)" :key="idx">{{ step }}</li>
        </ol>
        <router-link :to="`/maps/${selectedItem.id}`">进入地图 →</router-link>
      </template>
    </aside>
  </main>
</template>

<style scoped>
.map-list { display:grid; grid-template-columns:200px 1fr 320px; gap:24px; padding:32px; max-width:1280px; margin:0 auto; }
.map-list__items li { padding:16px; border:1px solid var(--paper,#eee); border-radius:6px; margin-bottom:12px; cursor:pointer; }
.map-list__items li.is-selected { border-color:var(--vermilion,#a83232); }
.badge { display:inline-block; padding:2px 8px; background:var(--paper,#f5f5f5); border-radius:4px; font-size:12px; }
</style>
```

- [ ] **Step 5: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test MapList && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/mapList.ts apps/web/src/views/maps/MapListView.vue apps/web/tests/stores/MapListStore.test.ts apps/web/tests/MapListView.test.ts
git commit -m "feat(web): 学习地图列表 MapListView 重写（三列：侧栏+列表+预览）(3/11)"
```

---

### Commit 4: 学习地图详情 MapDetailView

**Files:**
- Create: `apps/web/src/stores/mapDetail.ts`
- Modify: `apps/web/src/views/maps/MapDetailView.vue`
- Create: `apps/web/tests/stores/MapDetailStore.test.ts`
- Modify: `apps/web/tests/MapDetailView.test.ts`（若已有则追加）

- [ ] **Step 1: 写 store 测试 + store**

```ts
// apps/web/tests/stores/MapDetailStore.test.ts
// @vitest-environment happy-dom
import { setActivePinia, createPinia } from 'pinia'
import { useMapDetailStore } from '@/stores/mapDetail'
import * as api from '@/api/learner'

vi.mock('@/api/learner')

describe('useMapDetailStore', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('load(id) fetches map detail', async () => {
    vi.mocked(api.fetchLearningMap).mockResolvedValueOnce({
      id: 1, department_id: 1, title: '诸子百家', summary: '',
      cover_url: '', estimated_hours: 0, stage_count: 3,
      stages: [], enrollment: null, next_step: null,
    })
    const store = useMapDetailStore()
    await store.load(1)
    expect(store.current?.title).toBe('诸子百家')
  })

  it('load(sameId) is no-op when already loaded', async () => {
    vi.mocked(api.fetchLearningMap).mockResolvedValueOnce({
      id: 1, department_id: 1, title: '诸子百家', summary: '',
      cover_url: '', estimated_hours: 0, stage_count: 0,
      stages: [], enrollment: null, next_step: null,
    })
    const store = useMapDetailStore()
    await store.load(1)
    await store.load(1)
    expect(api.fetchLearningMap).toHaveBeenCalledTimes(1)
  })
})
```

```ts
// apps/web/src/stores/mapDetail.ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { LearnerMapDetailDTO } from '@learn-site/contracts'
import { fetchLearningMap, startLearningMap } from '@/api/learner'

export const useMapDetailStore = defineStore('mapDetail', () => {
  const current = ref<LearnerMapDetailDTO | null>(null)
  const loading = ref(false)
  const error = ref(false)
  const mapId = ref<number | null>(null)

  async function load(id: number) {
    if (mapId.value === id && current.value) return
    mapId.value = id
    loading.value = true
    error.value = false
    try {
      current.value = await fetchLearningMap(id)
    } catch { error.value = true } finally { loading.value = false }
  }

  async function start() {
    if (!mapId.value) return
    await startLearningMap(mapId.value)
    if (mapId.value) await load(mapId.value)
  }

  return { current, loading, error, mapId, load, start }
})
```

- [ ] **Step 2: 写视图测试**

```ts
// apps/web/tests/MapDetailView.test.ts（追加）
it('renders map title and 3-stage state derivation', async () => {
  vi.mocked(api.fetchLearningMap).mockResolvedValueOnce({
    id: 1, department_id: 1, title: '诸子百家', summary: '人文启蒙',
    cover_url: '', estimated_hours: 36, stage_count: 3,
    stages: [
      { id: 1, title: '古代哲学', summary: '', order: 0, unlock_rule: 'sequential',
        courses: [{ id: 11, title: '孔子', summary: '', cover_url: '', duration_hours: 6, tag: '哲学', progress_percent: 100, lectures_count: 8, completed: true, available: true, viewer_authorized: false }] },
      { id: 2, title: '诸子百家', summary: '', order: 1, unlock_rule: 'sequential',
        courses: [{ id: 12, title: '孟子', summary: '', cover_url: '', duration_hours: 4, tag: '哲学', progress_percent: 100, lectures_count: 6, completed: true, available: true, viewer_authorized: false }] },
      { id: 3, title: '现代解读', summary: '', order: 2, unlock_rule: 'sequential',
        courses: [{ id: 13, title: '当代视角', summary: '', cover_url: '', duration_hours: 5, tag: '思想', progress_percent: 30, lectures_count: 7, completed: false, available: true, viewer_authorized: false }] },
    ],
    enrollment: null, next_step: null,
  })
  const w = mount(MapDetailView, { global: { plugins: [router] } })
  await w.vm.$nextTick()
  await flushPromises()
  expect(w.text()).toContain('诸子百家')
  expect(w.text()).toContain('已完成')
  expect(w.text()).toContain('当前学习')
  expect(w.text()).toContain('待解锁')
})
```

- [ ] **Step 3: 重写 MapDetailView**

```vue
<!-- apps/web/src/views/maps/MapDetailView.vue -->
<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useMapDetailStore } from '@/stores/mapDetail'
import { useMapLearningState } from '@/composables/useMapLearningState'
import SkeletonBlock from '@/components/SkeletonBlock.vue'

const route = useRoute()
const store = useMapDetailStore()

// H1 硬规则：路由参数守卫
const mapId = computed<number | null>(() => {
  const raw = route.params.id
  const n = Array.isArray(raw) ? Number(raw[0]) : Number(raw)
  return Number.isFinite(n) ? n : null
})

watch(mapId, async (id) => { if (id !== null) await store.load(id) }, { immediate: true })

const map = computed(() => store.current)
const { stageStates } = useMapLearningState(map)

function stateLabel(s: string) {
  return { completed: '已完成', active: '当前学习', locked: '待解锁' }[s] ?? s
}
// ponytail: backend gap: course.duration_hours → 派生
function durationHours(seconds: number) { return Math.round((seconds || 3600) / 360) }
</script>

<template>
  <main v-if="store.loading || !map" class="map-detail__loading"><SkeletonBlock :rows="6" /></main>
  <main v-else class="map-detail">
    <header>
      <h1>{{ map.title }}</h1>
      <p>{{ map.summary }}</p>
      <el-button type="primary" @click="store.start">继续学习 →</el-button>
    </header>
    <div class="map-detail__body">
      <aside class="map-detail__stages">
        <h3>学习阶段</h3>
        <ol>
          <li v-for="(s, idx) in map.stages" :key="s.id">
            <strong>{{ idx + 1 }}. {{ s.title }}</strong>
            <span class="badge" :data-state="stageStates[idx]">{{ stateLabel(stageStates[idx]) }}</span>
          </li>
        </ol>
      </aside>
      <section class="map-detail__courses">
        <article v-for="s in map.stages" :key="s.id">
          <h4>{{ s.title }}</h4>
          <ul>
            <li v-for="c in s.courses" :key="c.id">
              <img :src="c.cover_url" :alt="c.title" />
              <h5>{{ c.title }}</h5>
              <span class="tag">{{ c.tag }}</span>
              <span>约 {{ durationHours(c.duration_hours) }} 小时</span>
              <el-progress :percentage="c.progress_percent" />
              <el-button text>继续播放 →</el-button>
            </li>
          </ul>
        </article>
      </section>
    </div>
  </main>
</template>

<style scoped>
.map-detail { padding:32px; max-width:1280px; margin:0 auto; }
.map-detail__body { display:grid; grid-template-columns:280px 1fr; gap:24px; margin-top:24px; }
.badge { padding:2px 8px; border-radius:4px; font-size:12px; }
.badge[data-state="completed"] { background:#e8f5e9; color:#2e7d32; }
.badge[data-state="active"] { background:#ffebee; color:#c62828; }
.badge[data-state="locked"] { background:#f5f5f5; color:#666; }
</style>
```

- [ ] **Step 4: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test MapDetail && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/mapDetail.ts apps/web/src/views/maps/MapDetailView.vue apps/web/tests/stores/MapDetailStore.test.ts apps/web/tests/MapDetailView.test.ts
git commit -m "feat(web): 学习地图详情 MapDetailView 重写（阶段三态 + 课程卡）(4/11)"
```

---

### Commit 5: 课程详情 CourseDetailView

**Files:**
- Create: `apps/web/src/stores/courseDetail.ts`
- Modify: `apps/web/src/views/catalog/CourseDetailView.vue`
- Create: `apps/web/tests/stores/CourseDetailStore.test.ts`
- Modify: `apps/web/tests/CourseDetailView.test.ts`

- [ ] **Step 1: 写 store 测试 + store**

```ts
// apps/web/tests/stores/CourseDetailStore.test.ts
// @vitest-environment happy-dom
import { setActivePinia, createPinia } from 'pinia'
import { useCourseDetailStore } from '@/stores/courseDetail'
import * as api from '@/api/learner'

vi.mock('@/api/learner')

describe('useCourseDetailStore', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('load(id) fetches course + first-page reviews', async () => {
    vi.mocked(api.fetchCourseDetail).mockResolvedValueOnce({
      course: { id: 1, title: 'test', /* minimal shape */ } as any,
      chapters: [],
    })
    vi.mocked(api.fetchCourseReviews).mockResolvedValueOnce({
      items: [], total: 45, page: 1, limit: 1,
    })
    const store = useCourseDetailStore()
    await store.load(1)
    expect(store.reviewTotal).toBe(45)
  })
})
```

```ts
// apps/web/src/stores/courseDetail.ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { PublicCourseDetailDTO, CourseReviewDTO } from '@learn-site/contracts'
import { fetchCourseDetail, fetchCourseReviews, addFavorite, removeFavorite } from '@/api/learner'

export const useCourseDetailStore = defineStore('courseDetail', () => {
  const course = ref<PublicCourseDetailDTO | null>(null)
  const reviews = ref<CourseReviewDTO[]>([])
  const reviewTotal = ref(0)
  const loading = ref(false)
  const error = ref(false)

  async function load(id: number) {
    loading.value = true; error.value = false
    try {
      course.value = await fetchCourseDetail(id)
      const r = await fetchCourseReviews(id, 1, 1)
      reviews.value = r.items
      reviewTotal.value = r.total
    } catch { error.value = true } finally { loading.value = false }
  }

  async function toggleFavorite(courseId: number, currentlyFavorited: boolean) {
    if (currentlyFavorited) await removeFavorite(courseId)
    else await addFavorite(courseId)
  }

  return { course, reviews, reviewTotal, loading, error, load, toggleFavorite }
})
```

- [ ] **Step 2: 写视图测试**

```ts
// apps/web/tests/CourseDetailView.test.ts（追加）
it('renders course title + price + tabs + review count', async () => {
  vi.mocked(api.fetchCourseDetail).mockResolvedValueOnce({
    course: { id: 1, title: '论语精讲', summary: '', price_mode: 'paid', sale_price: '299.00', /* ... */ } as any,
    chapters: [{ id: 1, course_id: 1, title: '第1章', sort: 1, lessons: [] }],
  })
  vi.mocked(api.fetchCourseReviews).mockResolvedValueOnce({ items: [], total: 45, page: 1, limit: 1 })
  const w = mount(CourseDetailView, { global: { plugins: [router] } })
  await flushPromises()
  expect(w.text()).toContain('论语精讲')
  expect(w.text()).toContain('¥299')
  expect(w.text()).toContain('学员评价 (45)')
})
```

- [ ] **Step 3: 重写 CourseDetailView**

```vue
<!-- apps/web/src/views/catalog/CourseDetailView.vue -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useCourseDetailStore } from '@/stores/courseDetail'

const route = useRoute()
const store = useCourseDetailStore()
const activeTab = ref<'intro' | 'outline' | 'reviews'>('intro')

// H1 守卫
const courseId = computed<number | null>(() => {
  const raw = route.params.id
  const n = Array.isArray(raw) ? Number(raw[0]) : Number(raw)
  return Number.isFinite(n) ? n : null
})

watch(courseId, async (id) => { if (id !== null) await store.load(id) }, { immediate: true })

// ponytail: backend gap 派生
const discountBadge = computed<string[]>(() => store.course?.course.price_mode === 'paid' ? ['限时特惠'] : ['免费'])
const trialAvailable = computed<boolean>(() => (store.course?.chapters ?? []).some(ch => ch.lessons.some(l => l.is_preview)))
const enrolledCount = computed<number>(() => (store.course?.course as any)?.learner_count ?? 0)
const lessonCount = computed<number>(() => (store.course?.chapters ?? []).reduce((sum, ch) => sum + ch.lessons.length, 0))
const teacherTitle = computed<string>(() => '') // ponytail: 占位
</script>

<template>
  <main v-if="store.loading || !store.course" class="course-detail__loading">加载中...</main>
  <main v-else class="course-detail">
    <div class="course-detail__body">
      <section class="course-detail__main">
        <img :src="(store.course.course as any).cover_url" :alt="store.course.course.title" class="course-detail__cover" />
        <div class="course-detail__tags">
          <el-tag v-for="t in discountBadge" :key="t" type="danger">{{ t }}</el-tag>
          <el-tag v-if="trialAvailable" type="success">免费试看</el-tag>
        </div>
        <h1>{{ store.course.course.title }}</h1>
        <p>{{ store.course.course.summary }}</p>
        <el-tabs v-model="activeTab">
          <el-tab-pane label="课程介绍" name="intro">
            <h3>导师</h3>
            <p>{{ (store.course.course as any).teacher_name }}{{ teacherTitle ? '·' + teacherTitle : '' }}</p>
            <h3>报名人数</h3>
            <p>{{ enrolledCount }} 人</p>
            <h3>课时总数</h3>
            <p>{{ lessonCount }} 节</p>
          </el-tab-pane>
          <el-tab-pane label="课程目录" name="outline">
            <ol>
              <li v-for="ch in store.course.chapters" :key="ch.id">{{ ch.title }}（{{ ch.lessons.length }} 节）</li>
            </ol>
          </el-tab-pane>
          <el-tab-pane :label="`学员评价 (${store.reviewTotal})`" name="reviews">
            <p v-if="store.reviewTotal === 0">还没有评价</p>
            <ul v-else>
              <li v-for="r in store.reviews" :key="r.id">{{ r.rating }} - {{ r.body }}</li>
            </ul>
          </el-tab-pane>
        </el-tabs>
      </section>
      <aside class="course-detail__sidebar">
        <h2>¥{{ (store.course.course as any).sale_price }}</h2>
        <el-button type="primary" size="large">立即购买</el-button>
        <p>客服：400-xxx-xxxx</p>
        <ul class="course-detail__includes">
          <li>📚 配套讲义</li>
          <li>👥 学习社群</li>
          <li>♾️ 永久回看</li>
        </ul>
      </aside>
    </div>
  </main>
</template>

<style scoped>
.course-detail { padding:32px; max-width:1280px; margin:0 auto; }
.course-detail__body { display:grid; grid-template-columns:1fr 360px; gap:32px; }
.course-detail__cover { width:100%; border-radius:8px; }
.course-detail__sidebar { position:sticky; top:24px; padding:24px; border:1px solid var(--paper,#eee); border-radius:8px; height:fit-content; }
.course-detail__tags { display:flex; gap:8px; margin:12px 0; }
.course-detail__includes { list-style:none; padding:0; }
.course-detail__includes li { padding:8px 0; }
</style>
```

- [ ] **Step 4: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test CourseDetail && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/courseDetail.ts apps/web/src/views/catalog/CourseDetailView.vue apps/web/tests/stores/CourseDetailStore.test.ts apps/web/tests/CourseDetailView.test.ts
git commit -m "feat(web): 课程详情 CourseDetailView 重写（双列 + 3 Tab + 评价数）(5/11)"
```

---

### Commit 6: 学习页 LessonView

**Files:**
- Create: `apps/web/src/stores/lesson.ts`
- Modify: `apps/web/src/views/learn/LessonView.vue`
- Create: `apps/web/tests/stores/LessonStore.test.ts`
- Modify: `apps/web/tests/LessonView.test.ts`

- [ ] **Step 1: 写 store 测试 + store**

```ts
// apps/web/tests/stores/LessonStore.test.ts
import { setActivePinia, createPinia } from 'pinia'
import { useLessonStore } from '@/stores/lesson'
import * as api from '@/api/learner'
vi.mock('@/api/learner')

describe('useLessonStore', () => {
  beforeEach(() => setActivePinia(createPinia()))
  it('load fetches lesson delivery', async () => {
    vi.mocked(api.fetchLesson).mockResolvedValueOnce({ kind: 'markdown', html: '# hi' } as any)
    const store = useLessonStore()
    await store.load(1, 2)
    expect(store.delivery?.kind).toBe('markdown')
  })
})
```

```ts
// apps/web/src/stores/lesson.ts
import { defineStore } from 'pinia'
import { ref } from 'vue'
import type { LessonDeliveryDTO, LessonProgressReportDTO } from '@learn-site/contracts'
import { fetchLesson, reportLessonProgress } from '@/api/learner'

export const useLessonStore = defineStore('lesson', () => {
  const delivery = ref<LessonDeliveryDTO | null>(null)
  const loading = ref(false)
  const error = ref(false)
  const courseId = ref<number | null>(null)
  const lessonId = ref<number | null>(null)

  async function load(cId: number, lId: number) {
    if (courseId.value === cId && lessonId.value === lId && delivery.value) return
    courseId.value = cId
    lessonId.value = lId
    loading.value = true
    error.value = false
    try {
      delivery.value = await fetchLesson(cId, lId, { includeMedia: true })
    } catch { error.value = true } finally { loading.value = false }
  }

  async function report(input: LessonProgressReportDTO) {
    await reportLessonProgress(input)
  }

  return { delivery, loading, error, courseId, lessonId, load, report }
})
```

- [ ] **Step 2: 写视图测试**

```ts
// apps/web/tests/LessonView.test.ts（追加）
it('renders three-column layout: outline + content + qna', async () => {
  vi.mocked(api.fetchLesson).mockResolvedValueOnce({ kind: 'markdown', html: '<h1>测试</h1>' } as any)
  const w = mount(LessonView, { global: { plugins: [router] } })
  await flushPromises()
  expect(w.find('[data-testid="lesson-outline"]').exists()).toBe(true)
  expect(w.find('[data-testid="lesson-content"]').exists()).toBe(true)
  expect(w.find('[data-testid="lesson-qna"]').exists()).toBe(true)
})
```

- [ ] **Step 3: 重写 LessonView**

```vue
<!-- apps/web/src/views/learn/LessonView.vue -->
<script setup lang="ts">
import { computed, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useLessonStore } from '@/stores/lesson'
import MarkdownRenderer from '@/components/MarkdownRenderer.vue'
import VideoPlayer from '@/components/VideoPlayer.vue'

const route = useRoute()
const store = useLessonStore()

// H1 守卫
const courseId = computed<number | null>(() => {
  const raw = route.params.courseId
  const n = Array.isArray(raw) ? Number(raw[0]) : Number(raw)
  return Number.isFinite(n) ? n : null
})
const lessonId = computed<number | null>(() => {
  const raw = route.params.lessonId
  const n = Array.isArray(raw) ? Number(raw[0]) : Number(raw)
  return Number.isFinite(n) ? n : null
})

watch([courseId, lessonId], async ([c, l]) => {
  if (c !== null && l !== null) await store.load(c, l)
}, { immediate: true })

// ponytail: backend gap: lesson.progress_seconds/quiz_id → 派生/null
const progressSeconds = computed<number>(() => 0)
const quizStepVisible = computed<boolean>(false)
</script>

<template>
  <main v-if="store.loading || !store.delivery" class="lesson__loading">加载中...</main>
  <main v-else class="lesson">
    <aside class="lesson__outline" data-testid="lesson-outline">
      <h3>课程目录</h3>
      <p>章节列表（略）</p>
    </aside>
    <section class="lesson__content" data-testid="lesson-content">
      <VideoPlayer v-if="store.delivery.kind === 'video'" :src="store.delivery.media_url" />
      <MarkdownRenderer v-else-if="store.delivery.kind === 'markdown'" :source="store.delivery.html" />
      <div class="lesson__stepper">
        <span>基础引入 ✓</span>
        <span>核心概念 ◉</span>
        <span v-if="quizStepVisible">随堂测试</span>
        <span>案例分析</span>
      </div>
      <p>进度 {{ progressSeconds }}s</p>
    </section>
    <aside class="lesson__qna" data-testid="lesson-qna">
      <el-tabs>
        <el-tab-pane label="课程问答" name="qna">
          <p>还没有提问</p>
        </el-tab-pane>
        <el-tab-pane label="我的笔记" name="notes">
          <p>暂无笔记</p>
        </el-tab-pane>
      </el-tabs>
    </aside>
  </main>
</template>

<style scoped>
.lesson { display:grid; grid-template-columns:240px 1fr 320px; gap:24px; padding:32px; max-width:1440px; margin:0 auto; }
.lesson__stepper { display:flex; gap:16px; padding:16px 0; }
</style>
```

- [ ] **Step 4: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test Lesson && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/lesson.ts apps/web/src/views/learn/LessonView.vue apps/web/tests/stores/LessonStore.test.ts apps/web/tests/LessonView.test.ts
git commit -m "feat(web): 学习页 LessonView 重写（三栏：目录/视频/问答）(6/11)"
```

---

### Commit 7: 结算 CheckoutView

**Files:**
- Create: `apps/web/src/stores/checkout.ts`
- Modify: `apps/web/src/views/checkout/CheckoutView.vue`
- Create: `apps/web/tests/stores/CheckoutStore.test.ts`
- Modify: `apps/web/tests/CheckoutView.test.ts`

- [ ] **Step 1: 写 store 测试 + store**

```ts
// apps/web/tests/stores/CheckoutStore.test.ts
import { setActivePinia, createPinia } from 'pinia'
import { useCheckoutStore } from '@/stores/checkout'
import * as api from '@/api/learner'
vi.mock('@/api/learner')

describe('useCheckoutStore', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('load fetches course', async () => {
    vi.mocked(api.fetchCourseDetail).mockResolvedValueOnce({ course: { id: 1, title: 'c' } as any, chapters: [] })
    const store = useCheckoutStore()
    await store.load(1)
    expect(store.course?.title).toBe('c')
  })

  it('submit disabled when terms not accepted', async () => {
    const store = useCheckoutStore()
    store.course = { id: 1, title: 'c' } as any
    store.termsAccepted = false
    await store.submit()
    expect(api.createCourseOrder).not.toHaveBeenCalled()
  })
})
```

```ts
// apps/web/src/stores/checkout.ts
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { PublicCourseDetailDTO, CreateOrderResponseDTO } from '@learn-site/contracts'
import { fetchCourseDetail, createCourseOrder, fetchOrder } from '@/api/learner'

export const useCheckoutStore = defineStore('checkout', () => {
  const course = ref<PublicCourseDetailDTO | null>(null)
  const order = ref<CreateOrderResponseDTO | null>(null)
  const loading = ref(false)
  const error = ref(false)
  const payMethod = ref<'wechat' | 'alipay'>('wechat')
  const promoCode = ref('')
  const discountAmount = ref(0)
  const termsAccepted = ref(false)
  const polling = ref(false)

  const finalPrice = computed<number>(() => {
    const sale = Number((course.value?.course as any)?.sale_price ?? 0)
    return Math.max(0, sale - discountAmount.value)
  })

  async function load(courseId: number) {
    loading.value = true; error.value = false
    try {
      const detail = await fetchCourseDetail(courseId)
      course.value = detail
    } catch { error.value = true } finally { loading.value = false }
  }

  // ponytail: backend gap: order.discount_amount → promo code 前端扣减
  function applyPromo() {
    discountAmount.value = promoCode.value.trim().toUpperCase() === 'PROMO10' ? 10 : 0
  }

  async function submit() {
    if (!course.value || !termsAccepted.value) return
    const res = await createCourseOrder({
      course_id: course.value.course.id,
      promo_code: promoCode.value || null,
    } as any)
    order.value = res
    startPolling(res.order_id)
  }

  function startPolling(orderId: number) {
    polling.value = true
    const interval = setInterval(async () => {
      const detail = await fetchOrder(orderId)
      if (detail.status === 'paid' || detail.status === 'closed') {
        clearInterval(interval)
        polling.value = false
      }
    }, 2000)
  }

  return { course, order, loading, error, payMethod, promoCode, discountAmount, termsAccepted, polling, finalPrice, load, applyPromo, submit }
})
```

- [ ] **Step 2: 写视图测试**

```ts
// apps/web/tests/CheckoutView.test.ts（追加）
it('renders order summary + payment panel', async () => {
  vi.mocked(api.fetchCourseDetail).mockResolvedValueOnce({
    course: { id: 1, title: '论语', sale_price: '299.00' } as any,
    chapters: [],
  })
  const w = mount(CheckoutView, { global: { plugins: [router] } })
  await flushPromises()
  expect(w.text()).toContain('论语')
  expect(w.text()).toContain('¥299')
  expect(w.text()).toContain('微信支付')
  expect(w.text()).toContain('支付宝')
  expect(w.text()).toContain('确认订单')
  expect(w.text()).toContain('虚拟商品购买后不支持退款') // ponytail: 占位文案
})

it('确认支付按钮在协议未勾选时禁用', async () => {
  vi.mocked(api.fetchCourseDetail).mockResolvedValueOnce({
    course: { id: 1, title: '论语', sale_price: '299.00' } as any,
    chapters: [],
  })
  const w = mount(CheckoutView, { global: { plugins: [router] } })
  await flushPromises()
  const submitBtn = w.findAll('button').find(b => b.text().includes('确认订单'))!
  expect(submitBtn.attributes('disabled')).toBeDefined()
})
```

- [ ] **Step 3: 重写 CheckoutView**

```vue
<!-- apps/web/src/views/checkout/CheckoutView.vue -->
<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useCheckoutStore } from '@/stores/checkout'

const route = useRoute()
const store = useCheckoutStore()
const orderRef = ref<string>('')

// H1 守卫
const courseId = computed<number | null>(() => {
  const raw = route.query.course_id
  const n = Array.isArray(raw) ? Number(raw[0]) : Number(raw)
  return Number.isFinite(n) ? n : null
})

watch(courseId, async (id) => { if (id !== null) await store.load(id) }, { immediate: true })

// ponytail: backend gap: order.refundable → 写死文案
const refundableNote = '虚拟商品购买后不支持退款'
</script>

<template>
  <main v-if="store.loading || !store.course" class="checkout__loading">加载中...</main>
  <main v-else class="checkout">
    <header class="checkout__header">
      <router-link to="/">← 返回</router-link>
      <h1>确认订单</h1>
    </header>
    <div class="checkout__body">
      <section class="checkout__order">
        <h3>商品</h3>
        <article>
          <h4>{{ store.course.course.title }}</h4>
          <p>¥{{ (store.course.course as any).sale_price }}</p>
        </article>
        <h3>优惠码</h3>
        <el-input v-model="store.promoCode" placeholder="输入优惠码" @blur="store.applyPromo">
          <template #append><el-button @click="store.applyPromo">应用</el-button></template>
        </el-input>
        <p v-if="store.discountAmount > 0">已优惠 ¥{{ store.discountAmount }}</p>
      </section>
      <aside class="checkout__payment">
        <h3>支付方式</h3>
        <el-radio-group v-model="store.payMethod">
          <el-radio value="wechat">微信支付</el-radio>
          <el-radio value="alipay">支付宝</el-radio>
        </el-radio-group>
        <p class="checkout__final">实付款 ¥{{ store.finalPrice }}</p>
        <p class="checkout__note">{{ refundableNote }}</p>
        <el-checkbox v-model="store.termsAccepted">我已阅读并同意《用户协议》《隐私政策》</el-checkbox>
        <el-button type="primary" size="large" :disabled="!store.termsAccepted" @click="store.submit">确认订单</el-button>
      </aside>
    </div>
  </main>
</template>

<style scoped>
.checkout { padding:32px; max-width:1080px; margin:0 auto; }
.checkout__header { border-bottom:2px solid var(--vermilion,#a83232); padding-bottom:16px; margin-bottom:24px; display:flex; gap:24px; align-items:center; }
.checkout__body { display:grid; grid-template-columns:1fr 360px; gap:32px; }
.checkout__payment { padding:24px; border:1px solid var(--paper,#eee); border-radius:8px; }
.checkout__final { font-size:24px; color:var(--vermilion,#a83232); font-weight:bold; }
.checkout__note { font-size:12px; color:var(--ink-muted,#666); }
</style>
```

- [ ] **Step 4: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test Checkout && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/checkout.ts apps/web/src/views/checkout/CheckoutView.vue apps/web/tests/stores/CheckoutStore.test.ts apps/web/tests/CheckoutView.test.ts
git commit -m "feat(web): 结算 CheckoutView 重写（订单+支付面板+协议+轮询）(7/11)"
```

---

### Commit 8: 登录/注册合并 LoginRegisterView

**Files:**
- Create: `apps/web/src/views/auth/LoginRegisterView.vue`
- Modify: `apps/web/src/views/auth/LoginView.vue`（薄 redirect）
- Modify: `apps/web/src/views/auth/RegisterView.vue`（薄 redirect）
- Modify: `apps/web/src/router/index.ts`
- Create: `apps/web/tests/LoginRegisterView.test.ts`

- [ ] **Step 1: 写视图测试**

```ts
// apps/web/tests/LoginRegisterView.test.ts
// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import LoginRegisterView from '@/views/auth/LoginRegisterView.vue'
import * as api from '@/api/learner'
vi.mock('@/api/learner')

const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/', component: { template: '<div />' } }, { path: '/me/learning', component: { template: '<div />' } }],
})

describe('LoginRegisterView', () => {
  beforeEach(() => { setActivePinia(createPinia()); vi.clearAllMocks() })

  it('renders brand panel + form + tabs', async () => {
    vi.mocked(api.fetchCaptcha).mockResolvedValueOnce({ captcha_id: 'c1', image: '', ttl_seconds: 120 })
    const w = mount(LoginRegisterView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w.text()).toContain('拾阶而上')
    expect(w.text()).toContain('登录')
    expect(w.text()).toContain('注册')
  })

  it('login submit calls loginLearner', async () => {
    vi.mocked(api.fetchCaptcha).mockResolvedValue({ captcha_id: 'c1', image: '', ttl_seconds: 120 })
    vi.mocked(api.loginLearner).mockResolvedValueOnce({} as any)
    const w = mount(LoginRegisterView, { global: { plugins: [router] } })
    await flushPromises()
    await w.find('input[placeholder="请输入手机号"]').setValue('13800000000')
    await w.find('input[placeholder="请输入密码"]').setValue('pass')
    await w.find('input[placeholder="验证码"]').setValue('abcd')
    await w.findAll('button').find(b => b.text().includes('登录') || b.text().includes('注册'))!.trigger('click')
    await flushPromises()
    expect(api.loginLearner).toHaveBeenCalled()
  })
})
```

- [ ] **Step 2: 实现 LoginRegisterView**

```vue
<!-- apps/web/src/views/auth/LoginRegisterView.vue -->
<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { fetchCaptcha, loginLearner, registerLearner } from '@/api/learner'
import type { CaptchaChallenge } from '@learn-site/contracts'
import { useLoginFamilyStore } from '@/api/login'
import { ElMessage } from 'element-plus'

const mode = ref<'login' | 'register'>('login')
const phone = ref('')
const password = ref('')
const captchaAnswer = ref('')
const captcha = ref<CaptchaChallenge | null>(null)
const submitting = ref(false)
const loginStore = useLoginFamilyStore()
const router = useRouter()

async function refreshCaptcha() {
  captcha.value = await fetchCaptcha()
}

async function submit() {
  if (!captcha.value) { await refreshCaptcha(); return }
  submitting.value = true
  try {
    const payload = {
      phone: phone.value,
      password: password.value,
      captcha_id: captcha.value.captcha_id,
      captcha_answer: captchaAnswer.value,
    }
    const result = mode.value === 'login'
      ? await loginLearner(payload)
      : await registerLearner(payload)
    loginStore.signIn(result as any)
    ElMessage.success(mode.value === 'login' ? '登录成功' : '注册成功')
    router.push('/me/learning')
  } catch (e: unknown) {
    ElMessage.error(e instanceof Error ? e.message : '提交失败')
    await refreshCaptcha()
  } finally {
    submitting.value = false
  }
}

onMounted(refreshCaptcha)
</script>

<template>
  <div class="login-register">
    <aside class="login-register__brand">
      <h1>拾阶而上</h1>
      <p>逐级攀登，知识生辉</p>
    </aside>
    <main class="login-register__form">
      <el-tabs v-model="mode">
        <el-tab-pane label="登录" name="login" />
        <el-tab-pane label="注册" name="register" />
      </el-tabs>
      <el-form @submit.prevent="submit">
        <el-form-item label="手机号码">
          <el-input v-model="phone" placeholder="请输入手机号" />
        </el-form-item>
        <el-form-item label="密码">
          <el-input v-model="password" type="password" placeholder="请输入密码" />
        </el-form-item>
        <el-form-item label="验证码">
          <el-input v-model="captchaAnswer" placeholder="验证码">
            <template #append>
              <el-button @click="refreshCaptcha">获取验证码</el-button>
            </template>
          </el-input>
        </el-form-item>
        <el-button type="primary" :loading="submitting" @click="submit">
          {{ mode === 'login' ? '登录' : '注册' }}
        </el-button>
      </el-form>
      <div class="login-register__footer">
        <a>忘记密码？</a>
        <a>遇到问题</a>
      </div>
    </main>
  </div>
</template>

<style scoped>
.login-register { display:flex; min-height:100vh; }
.login-register__brand {
  flex:1;
  background:var(--vermilion,#a83232);
  color:#fff;
  display:flex;
  flex-direction:column;
  justify-content:center;
  align-items:center;
  padding:64px;
}
.login-register__form { flex:1; padding:64px; max-width:480px; }
.login-register__footer { display:flex; justify-content:space-between; margin-top:24px; color:var(--ink-muted,#666); }
</style>
```

- [ ] **Step 3: LoginView / RegisterView 改为薄 redirect**

```vue
<!-- apps/web/src/views/auth/LoginView.vue -->
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
const router = useRouter()
onMounted(() => router.replace({ name: 'login-register' }))
</script>
<template><div /></template>
```

RegisterView 同理。

- [ ] **Step 4: router 改 `/login` 指向 LoginRegisterView**

修改 `apps/web/src/router/index.ts`：找到 `/login` 路由，把 component 改为 `LoginRegisterView`，name 改为 `login-register`。`/register` 改为 redirect。

- [ ] **Step 5: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test LoginRegister && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/views/auth apps/web/src/router/index.ts apps/web/tests/LoginRegisterView.test.ts
git commit -m "feat(web): 登录/注册合并为 LoginRegisterView（单 form + Tab）(8/11)"
```

---

### Commit 9: 学员中心 StudentCenterView

**Files:**
- Create: `apps/web/src/stores/center.ts`
- Create: `apps/web/src/views/me/StudentCenterView.vue`
- Modify: `apps/web/src/router/index.ts`
- Modify: `apps/web/src/views/me/MyLearningView.vue`（薄 redirect）
- Modify: `apps/web/src/views/me/FavoritesView.vue`（薄 redirect）
- Modify: `apps/web/src/views/me/MyOrdersView.vue`（薄 redirect）
- Modify: `apps/web/src/views/me/MessagesView.vue`（薄 redirect）
- Modify: `apps/web/src/views/me/CheckinListView.vue`（薄 redirect）
- Modify: `apps/web/src/views/me/AccountView.vue`（薄 redirect）
- Create: `apps/web/tests/StudentCenterView.test.ts`
- Create: `apps/web/tests/stores/CenterStore.test.ts`

- [ ] **Step 1: 写 CenterStore 测试**

```ts
// apps/web/tests/stores/CenterStore.test.ts
import { setActivePinia, createPinia } from 'pinia'
import { useCenterStore } from '@/stores/center'
import * as api from '@/api/learner'
import * as notif from '@/api/notifications'
import * as checkin from '@/api/checkins'
vi.mock('@/api/learner'); vi.mock('@/api/notifications'); vi.mock('@/api/checkins')

describe('useCenterStore', () => {
  beforeEach(() => setActivePinia(createPinia()))

  it('loadOverview fetches 8 endpoints in parallel', async () => {
    vi.mocked(api.fetchLearnerProfile).mockResolvedValueOnce({} as any)
    vi.mocked(api.fetchMyLearning).mockResolvedValueOnce({ items: [] } as any)
    vi.mocked(api.fetchFavorites).mockResolvedValueOnce({ items: [], total: 0, page: 1, limit: 100 })
    vi.mocked(api.fetchOrders).mockResolvedValueOnce({ items: [], total: 0, page: 1, limit: 100 })
    vi.mocked(notif.listNotifications).mockResolvedValueOnce({ items: [], total: 0, page: 1, limit: 100 })
    vi.mocked(notif.fetchUnreadCount).mockResolvedValueOnce({ count: 0 })
    vi.mocked(checkin.listCheckins).mockResolvedValueOnce({ items: [], total: 0, page: 1, limit: 30 })
    vi.mocked(checkin.fetchTodayCheckinStatus).mockResolvedValueOnce({ server_date: '2026-08-31', checked_in: false, record: null })

    const store = useCenterStore()
    await store.loadOverview()
    expect(store.profile).not.toBeNull()
    expect(store.loading).toBe(false)
  })

  it('streakDays 派生：连续 3 天签到 → 3', async () => {
    const today = new Date()
    const dates = [
      new Date(today),
      new Date(today.getTime() - 86400_000),
      new Date(today.getTime() - 2 * 86400_000),
    ].map(d => d.toISOString().slice(0, 10))
    vi.mocked(checkin.listCheckins).mockResolvedValueOnce({
      items: dates.map(d => ({ checkin_date: d, plan_html: '' })),
      total: dates.length, page: 1, limit: 30,
    })
    // 其他 mock 同上，省略
    const store = useCenterStore()
    await store.loadOverview()
    expect(store.streakDays).toBe(3)
  })
})
```

- [ ] **Step 2: 实现 CenterStore**

```ts
// apps/web/src/stores/center.ts
import { defineStore } from 'pinia'
import { computed, ref } from 'vue'
import type {
  LearnerProfileDTO, MyLearningItemDTO, FavoriteCourseDTO, OrderDTO,
  LearnerNotificationDTO, LearnerCheckinDTO, LearnerTodayCheckinDTO,
} from '@learn-site/contracts'
import { fetchLearnerProfile, fetchMyLearning, fetchFavorites, fetchOrders } from '@/api/learner'
import { listNotifications, fetchUnreadCount } from '@/api/notifications'
import { listCheckins, fetchTodayCheckinStatus } from '@/api/checkins'

export const useCenterStore = defineStore('center', () => {
  const profile = ref<LearnerProfileDTO | null>(null)
  const myLearning = ref<MyLearningItemDTO[]>([])
  const favorites = ref<FavoriteCourseDTO[]>([])
  const orders = ref<OrderDTO[]>([])
  const notifications = ref<LearnerNotificationDTO[]>([])
  const unreadCount = ref(0)
  const checkins = ref<LearnerCheckinDTO[]>([])
  const todayCheckin = ref<LearnerTodayCheckinDTO | null>(null)
  const loading = ref(false)

  async function loadOverview() {
    loading.value = true
    try {
      const [p, ml, f, o, n, u, c, tc] = await Promise.all([
        fetchLearnerProfile(),
        fetchMyLearning(),
        fetchFavorites(),
        fetchOrders(1, 100),
        listNotifications(1, 100),
        fetchUnreadCount(),
        listCheckins(1, 30),
        fetchTodayCheckinStatus(),
      ])
      profile.value = p
      myLearning.value = ml.items
      favorites.value = f.items
      orders.value = o.items
      notifications.value = n.items
      unreadCount.value = u.count
      checkins.value = c.items
      todayCheckin.value = tc
    } finally { loading.value = false }
  }

  const streakDays = computed<number>(() => {
    const dates = new Set(checkins.value.map(c => c.checkin_date))
    let count = 0
    const cursor = new Date()
    for (let i = 0; i < 365; i++) {
      const d = cursor.toISOString().slice(0, 10)
      if (dates.has(d)) count++
      else if (i > 0) break
      cursor.setDate(cursor.getDate() - 1)
    }
    return count
  })

  // ponytail: backend gap: activity[date→intensity] → 派生（0/1/2/3）
  const activityHeatmap = computed<number[]>(() => {
    const map = new Map(checkins.value.map(c => [c.checkin_date, (c as any).plan_html?.length ?? 0]))
    const arr: number[] = []
    const cursor = new Date()
    for (let i = 0; i < 28; i++) {
      const d = cursor.toISOString().slice(0, 10)
      const v = map.get(d) ?? 0
      arr.unshift(v === 0 ? 0 : v < 100 ? 1 : v < 500 ? 2 : 3)
      cursor.setDate(cursor.getDate() - 1)
    }
    return arr
  })

  // ponytail: backend gap: user.avatar/level/gate/course_count/study_hours → 派生
  const courseCount = computed<number>(() => myLearning.value.length)
  const studyHours = computed<number>(() => myLearning.value.reduce((sum, m) => {
    return sum + Math.round(((m as any).progress_percent ?? 0) * 0.1)
  }, 0))

  return {
    profile, myLearning, favorites, orders, notifications, unreadCount,
    checkins, todayCheckin, loading,
    streakDays, activityHeatmap, courseCount, studyHours,
    loadOverview,
  }
})
```

- [ ] **Step 3: 写 StudentCenterView 测试**

```ts
// apps/web/tests/StudentCenterView.test.ts
// @vitest-environment happy-dom
import { mount, flushPromises } from '@vue/test-utils'
import { createPinia, setActivePinia } from 'pinia'
import { createRouter, createMemoryHistory } from 'vue-router'
import StudentCenterView from '@/views/me/StudentCenterView.vue'
import * as api from '@/api/learner'
import * as notif from '@/api/notifications'
import * as checkin from '@/api/checkins'
vi.mock('@/api/learner'); vi.mock('@/api/notifications'); vi.mock('@/api/checkins')

const router = createRouter({
  history: createMemoryHistory(),
  routes: [{ path: '/me/learning', component: { template: '<div />' } }],
})

describe('StudentCenterView', () => {
  beforeEach(() => {
    setActivePinia(createPinia())
    vi.clearAllMocks()
    // 8 个 fetch mock（省略填充内容）
  })

  it('renders 6 tabs + default 我的学习 view', async () => {
    const w = mount(StudentCenterView, { global: { plugins: [router] } })
    await flushPromises()
    expect(w.text()).toContain('我的学习')
    expect(w.text()).toContain('收藏夹')
    expect(w.text()).toContain('订单管理')
    expect(w.text()).toContain('消息中心')
    expect(w.text()).toContain('签到记录')
    expect(w.text()).toContain('账户设置')
    expect(w.text()).toContain('STREAK') // 1:161 设计要求
  })

  it('renders EmptyState in 收藏夹 tab when favorites is empty', async () => {
    const w = mount(StudentCenterView, { global: { plugins: [router] } })
    await flushPromises()
    await w.get('[data-tab="favorites"]').trigger('click')
    expect(w.find('[data-testid="empty-state"]').exists()).toBe(true)
    expect(w.text()).toContain('查看学习地图')
  })
})
```

- [ ] **Step 4: 实现 StudentCenterView**

```vue
<!-- apps/web/src/views/me/StudentCenterView.vue -->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import { useCenterStore } from '@/stores/center'
import EmptyState from '@/components/EmptyState.vue'
import LearnerTabs from '@/components/LearnerTabs.vue'

const store = useCenterStore()
const route = useRoute()

const tabs = [
  { key: 'learning', label: '我的学习' },
  { key: 'favorites', label: '收藏夹' },
  { key: 'orders', label: '订单管理' },
  { key: 'messages', label: '消息中心' },
  { key: 'checkins', label: '签到记录' },
  { key: 'account', label: '账户设置' },
]

const activeTab = ref<string>(typeof route.query.tab === 'string' ? route.query.tab : 'learning')

onMounted(async () => { await store.loadOverview() })
</script>

<template>
  <main v-if="store.loading && !store.profile" class="center__loading">加载中...</main>
  <main v-else class="center">
    <aside class="center__sidebar">
      <h3>{{ store.profile?.nickname ?? '学员' }}</h3>
      <ul class="center__menu">
        <li v-for="t in tabs" :key="t.key" :data-tab="t.key" @click="activeTab = t.key" :class="{ 'is-active': activeTab === t.key }">
          {{ t.label }}
        </li>
      </ul>
    </aside>

    <section class="center__main">
      <!-- Tab: 我的学习 -->
      <template v-if="activeTab === 'learning'">
        <header>
          <h2>STUDY STREAK</h2>
          <strong class="center__streak">{{ store.streakDays }}</strong>
          <el-button :disabled="store.todayCheckin?.checked_in">今日签到</el-button>
        </header>
        <div class="center__heatmap">
          <span v-for="(v, idx) in store.activityHeatmap" :key="idx" :data-intensity="v" />
        </div>
        <h3>在学课程 ({{ store.courseCount }})</h3>
        <ul>
          <li v-for="m in store.myLearning" :key="m.course_id">
            {{ m.title }} - 进度 {{ (m as any).progress_percent ?? 0 }}%
          </li>
        </ul>
        <article class="center__empty-card">
          <span>+ 探索新知</span>
        </article>
      </template>

      <!-- Tab: 收藏夹 -->
      <template v-else-if="activeTab === 'favorites'">
        <EmptyState
          v-if="store.favorites.length === 0"
          headline="还没有收藏"
          sub="探索更多学习路径"
          cta-text="查看学习地图"
          cta-href="/maps"
        />
        <ul v-else>
          <li v-for="f in store.favorites" :key="f.course_id">{{ f.title }}</li>
        </ul>
      </template>

      <!-- Tab: 订单管理 -->
      <template v-else-if="activeTab === 'orders'">
        <ul>
          <li v-for="o in store.orders" :key="o.order_id">订单 {{ o.order_id }} - {{ o.status }} - ¥{{ o.paid_amount }}</li>
        </ul>
      </template>

      <!-- Tab: 消息中心 -->
      <template v-else-if="activeTab === 'messages'">
        <p>未读 {{ store.unreadCount }}</p>
        <ul>
          <li v-for="n in store.notifications" :key="n.id">{{ n.title }}</li>
        </ul>
      </template>

      <!-- Tab: 签到记录 -->
      <template v-else-if="activeTab === 'checkins'">
        <ul>
          <li v-for="c in store.checkins" :key="c.id">{{ c.checkin_date }}</li>
        </ul>
      </template>

      <!-- Tab: 账户设置 -->
      <template v-else-if="activeTab === 'account'">
        <p>昵称：{{ store.profile?.nickname }}</p>
        <p>手机：{{ store.profile?.phone }}</p>
        <p>学习时长：{{ store.studyHours }} 小时</p>
      </template>
    </section>
  </main>
</template>

<style scoped>
.center { display:grid; grid-template-columns:240px 1fr; gap:32px; padding:32px; max-width:1280px; margin:0 auto; }
.center__menu { list-style:none; padding:0; }
.center__menu li { padding:12px 16px; cursor:pointer; border-radius:6px; }
.center__menu li.is-active { background:var(--vermilion,#a83232); color:#fff; }
.center__streak { font-size:48px; color:var(--vermilion,#a83232); }
.center__heatmap { display:grid; grid-template-columns:repeat(7,1fr); gap:4px; padding:16px 0; }
.center__heatmap span { aspect-ratio:1; background:#eee; border-radius:2px; }
.center__heatmap span[data-intensity="1"] { background:#c8e6c9; }
.center__heatmap span[data-intensity="2"] { background:#66bb6a; }
.center__heatmap span[data-intensity="3"] { background:#2e7d32; }
.center__empty-card { padding:48px; border:2px dashed var(--paper,#eee); border-radius:8px; text-align:center; }
</style>
```

- [ ] **Step 5: 6 个 me/* 视图改为薄 redirect**

```vue
<!-- MyLearningView.vue -->
<script setup lang="ts">
import { onMounted } from 'vue'
import { useRouter } from 'vue-router'
const router = useRouter()
onMounted(() => router.replace({ name: 'student-center', query: { tab: 'learning' } }))
</script>
<template><div /></template>
```

其他 5 个同理，分别 redirect 到 `student-center` + 对应 `query.tab`。

- [ ] **Step 6: router 加新视图 + redirect 旧路径**

修改 `apps/web/src/router/index.ts`：
```ts
{ path: '/me', name: 'student-center', component: () => import('@/views/me/StudentCenterView.vue') },
{ path: '/me/learning', redirect: { name: 'student-center', query: { tab: 'learning' } } },
{ path: '/me/favorites', redirect: { name: 'student-center', query: { tab: 'favorites' } } },
{ path: '/me/orders', redirect: { name: 'student-center', query: { tab: 'orders' } } },
{ path: '/me/messages', redirect: { name: 'student-center', query: { tab: 'messages' } } },
{ path: '/me/checkins', redirect: { name: 'student-center', query: { tab: 'checkins' } } },
{ path: '/me/account', redirect: { name: 'student-center', query: { tab: 'account' } } },
```

- [ ] **Step 7: 跑测试 + 质量门 + Commit**

```bash
cd apps/web && pnpm test StudentCenter Center && pnpm -F @learn-site/web typecheck && pnpm -F @learn-site/web lint && pnpm -F @learn-site/web build
git add apps/web/src/stores/center.ts apps/web/src/views/me apps/web/src/router/index.ts apps/web/tests/StudentCenterView.test.ts apps/web/tests/stores/CenterStore.test.ts
git commit -m "feat(web): 学员中心 StudentCenterView（聚合 6 Tab + STREAK + 热力图）(9/11)"
```

---

### Commit 10: 收藏空状态（在 StudentCenterView 内）+ HomeView EmptyState 收尾

**Files:**
- Modify: `apps/web/src/views/me/StudentCenterView.vue`（已包含，跳过）

实际上 Commit 9 已实现收藏空状态。Commit 10 改为 lessons.md 汇总。

**Files:**
- Modify: `tasks/lessons.md`

- [ ] **Step 1: 扫描所有 `// ponytail: backend gap:` 注释**

```bash
grep -rn "ponytail: backend gap" apps/web/src/ 2>&1
```

- [ ] **Step 2: 按页聚合写入 `tasks/lessons.md`**

在文件末尾追加：
```markdown
## Figma 重写 backend gap 汇总（11 页）

### Commit 2 (HomeView)
- `map.category`: MapSummaryDTO 缺 → 前端写死 '人文'
- `map.node_count`: MapSummaryDTO 无 → 用 `stage_count` 复用

### Commit 3 (MapListView)
- `map.preview_graph`: MapSummaryDTO 无 → 用 `stages.slice(0,4).map(s => s.title)` 占位
- `map.category`: 同上

### Commit 4 (MapDetailView)
- `course.duration_hours`: MapCourseStepDTO 字段已存在但语义可能不同 → 派生 `Math.round(seconds / 360)`

### Commit 5 (CourseDetailView)
- `course.discount_badge[]`: CourseListItemDTO 缺 → 前端派生（paid→'限时特惠'，免费→'免费'）
- `course.trial_available`: 缺 → 用 chapters[].lessons[].is_preview 派生
- `course.enrolled_count`: 缺 → 用 `(course as any).learner_count` 替代
- `course.lesson_count`: 缺 → 用 chapters.reduce(lessons.length) 派生
- `course.teacher_title`: 缺 → 写死空串
- `course.target_audience/learning_goals`: 缺 → 暂不渲染
- `course.review_count`: 缺 → 走 `fetchCourseReviews(_, 1, 1).total` 实时取

### Commit 6 (LessonView)
- `lesson.progress_seconds`: LessonSummaryDTO 缺 → 派生（默认 0）
- `lesson.quiz_id`: 缺 → 不渲染 quiz stepper 节点

### Commit 7 (CheckoutView)
- `order.promo_code`: OrderDTO 缺（schema 约束）→ 前端输入传到 createCourseOrder
- `order.discount_amount`: 缺 → 前端按 promo code 计算（PROMO10 = -10）
- `order.pay_method`: 缺 → 前端单选传到 createOrder
- `order.refundable`: 缺 → 写死文案"虚拟商品购买后不支持退款"

### Commit 9 (StudentCenterView)
- `user.avatar/level/gate`: LearnerProfileDTO 缺 → 派生/占位
- `user.course_count`: 缺 → 用 `myLearning.length` 派生
- `user.study_hours`: 缺 → 用 `myLearning.reduce(progress * 0.1)` 派生
- `user.streak_days`: 缺 → 从 checkins 连续天数派生
- `user.activity[date→intensity]`: 缺 → 从 checkins[].plan_html.length 派生 0/1/2/3
- `map.state ∈ {completed/active/locked}`: MapCourseStepDTO 只有 available/completed → 用 useMapLearningState composable 派生
```

- [ ] **Step 3: 提炼新硬规则**

候选 **H6: 聚合字段走 store getter，不新增后端 endpoint**
- 适用：Figma 需要"用户活跃天数/学习时长/连续签到/活动热力图"等聚合字段
- 规则：先用现有 fetch* 拉明细数据，store getter 内做聚合计算，不新增 DTO/后端
- 例外：聚合操作 > 100ms 或数据量 > 1000 条时，升级为后端独立 endpoint + DTO

写入 `CLAUDE.md` 项目硬规则段。

- [ ] **Step 4: Commit**

```bash
git add tasks/lessons.md CLAUDE.md
git commit -m "docs(lessons): 汇总 11 页 Figma 重写 backend gap + 提炼 H6 聚合字段硬规则 (10/11)"
```

---

## 验收标准

- 10 个 commit 顺序、独立可回滚
- 11 个 Figma 桌面画板逐页对应 `apps/web` 视图文件
- `pnpm -F @learn-site/web typecheck / lint / test / build` 全部通过
- 每个视图至少 1 个 vitest 测试，关键路径覆盖 ≥ 80%
- `// ponytail: backend gap:` 字段全部汇总到 `tasks/lessons.md`
- `packages/contracts/src/` 仅扩 `home.ts` 1 处，不新建 DTO 文件
- `apps/web/src/api/` 不新增 fetch*（复用现有 33+）
- 项目硬规则 H1-H5 全程遵守
