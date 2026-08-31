# Quickstart 验证指南：学习端 Stitch 设计对齐

目标：验证 [spec.md](./spec.md) 用户故事与 [contracts/](./contracts/) 视觉契约。实现任务见 `tasks.md`（`/speckit-tasks` 生成）。

## 前置

```bash
docker compose up -d --build
```

- 学习端：`http://localhost:${WEB_PORT:-8080}`
- 管理端（可选，配置轮播）：`http://localhost:${ADMIN_PORT:-8081}`
- Stitch 参考：项目 ID `673993425689807844`（可用 Stitch MCP 导出 HTML 至 `reference/`）

## 自动化门禁

```bash
make test-web
```

通过标准：Prettier、ESLint、TypeScript、`apps/web` Vitest、生产构建全部通过；不得为通过测试删除 spec 要求的 `data-testid` / `data-action` 钩子。

## 视觉走查清单（7 个主页面）

在 **1280px** 与 **375px** 宽度下各执行一次：

| # | 路由 | 检查点 |
|---|------|--------|
| 1 | `/` | 顶栏单行；有 banners 时轮播来自接口；分类侧栏 + 课程列表；无横向滚动 |
| 2 | `/courses/{id}` | 封面/价格/CTA 布局；长标题不遮挡按钮 |
| 3 | `/learn/{courseId}/{lessonId}` | 登录学员；三栏/移动单列；视频区与目录可切换 |
| 4 | `/me/learning` | 侧栏 + 内容区；tab 与 URL 一致 |
| 5 | `/maps` 与 `/maps/{id}` | 卡片与详情时间轴 |
| 6 | `/checkout/{courseId}` | 订单摘要与支付方式选中态 |
| 7 | `/login` | 品牌区 + 表单 + 验证码 |

**夜读模式**：在顶栏切换夜读，重复首页与课程详情可读性检查。

## 数据场景

| 场景 | 操作 | 预期 |
|------|------|------|
| 首页有轮播 | 管理端创建并启用 banner | `GET /home` 含 `banners`；首页展示轮播，非静态占位图 |
| 首页无轮播 | 禁用全部 banner | 主视觉区留空，直接进入目录与列表 |
| 空分类课程 | 选无课程分类 | `el-empty` 提示 |
| 接口失败 | 临时停 api | `el-alert` 错误，导航仍可用 |

## 轮播接口抽查

```bash
curl -fsS "http://localhost:${WEB_PORT:-8080}/api/learner/v1/home" | jq '.data.banners'
```

## 回归：核心业务未改

确认以下行为与对齐前一致（仅样式变化）：

- 访客可浏览首页与课程详情
- 登录/刷新令牌流程正常
- 付费课程跳转结算；免费/已授权进入学习
- 个人中心各 tab 数据加载
- 地图列表与详情 enrollment 状态

## 可选：归档 Stitch HTML

```bash
mkdir -p specs/007-stitch-web-fidelity/reference
# 通过 Stitch MCP get_screen 保存各桌面屏 HTML，供后续 diff 对照
```

## 失败时排查

| 现象 | 检查 |
|------|------|
| 首页无轮播 | `banners` 是否为空；`HomeView` 是否 `load({ force: true })` |
| 样式未更新 | 浏览器强刷；`docker compose build web` |
| 测试失败 | 文案/类名变更是否同步 `apps/web/tests/` |
| 横向溢出 | DevTools 检查 375px 下 `overflow-x` 来源元素 |
