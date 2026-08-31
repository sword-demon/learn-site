# 视觉走查表（007-stitch-web-fidelity）

**日期**: 2026-08-31  
**断点**: 375px（移动）、1280px（桌面）  
**自动化**: `make test-web` + `apps/web` Vitest 120 项通过

## 7 个主路由走查

| # | 路由 | 组件 | 375px | 1280px | 空/错态 | 备注 |
|---|------|------|-------|--------|---------|------|
| 1 | `/` | `HomeView.vue` | ✓ 单列 home-grid | ✓ 侧栏+列表 | skeleton/empty/alert | 轮播来自 `home.banners` |
| 2 | `/courses/:id` | `CourseDetailView.vue` | ✓ 堆叠 | ✓ hero+侧栏 | alert | 长标题换行 |
| 3 | `/learn/:courseId/:lessonId` | `LessonView.vue` | ✓ 单列 | ✓ 三栏 | 需登录 | 夜读可读 |
| 4 | `/me/learning` 等 | `StudentCenterView.vue` | ✓ | ✓ 侧栏+内容 | empty | URL tab |
| 5 | `/maps` | `MapListView.vue` | ✓ 卡片 | ✓ 网格 | empty | fallback 图 |
| 6 | `/maps/:id` | `MapDetailView.vue` | ✓ | ✓ 时间轴 | alert | |
| 7 | `/login` | `LoginRegisterView.vue` | ✓ | ✓ 双栏→单列 | 表单错误 | hideFooter |
| 8 | `/checkout/:id` | `CheckoutView.vue` | ✓ | ✓ | 需登录 | 支付选中态 |

图例：✓ = 自动化/代码审查通过（无横向 `overflow-x` 于 `html/body`）；人工截图对比 Stitch 为可选增强。

## 壳层（Foundational）

| 项 | 文件 | 验收 |
|----|------|------|
| 顶栏单行品牌+导航 | `LearnerLayout.vue` | `LearnerLayout.test.ts` |
| 居中页脚 | `SiteFooter.vue` | `AppFooter.test.ts` |
| 全局 token | `style.css` | Element Plus 主色 `--seal` |

## 数据场景

| 场景 | 验证方式 |
|------|----------|
| 有 banners | `curl /api/learner/v1/home \| jq .data.banners` |
| 无 banners | 轮播区不占位 |
| 地图无封面 | `stitch-map-*.jpg` fallback |

## 回归命令

```bash
cd apps/web && pnpm exec vitest run
make test-web
```
