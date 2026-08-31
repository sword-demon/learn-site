# 学习端页面表面契约

描述各路由在 Stitch 对齐后的**结构、状态与验收点**。业务行为与 API 见 `specs/001-personal-learning-site/contracts/learner-api.md`。

## 首页 `/`

**参考**: Stitch 首页桌面屏幕  
**文件**: `HomeView.vue`, `HomeBannerCarousel.vue`, `CourseEntryRow.vue`

| 区域 | 要求 |
|------|------|
| 轮播 | `banners.length > 0` 时展示 `el-carousel`；图片来自 `banner.image_url`；首屏可叠 `site_intro.title`；无数据不占位 |
| 侧栏 | 「拾阶目录」+ 全部分类 + `el-tree`；sticky |
| 主栏 | 面包屑 + 标题 + 课程 `entry-list` |
| 地图推荐 | `recommended_maps` 三列卡片（有数据时） |

**状态**:
- `loading`: `el-skeleton`
- `error`: `el-alert`「目录暂时读不到」
- 空课程: `el-empty`

**测试钩子**: `data-testid="home-banner-carousel"`, `data-action="all-categories"`, `data-testid="recommended-map-rail"`

---

## 课程详情 `/courses/:id`

**文件**: `CourseDetailView.vue`

| 区域 | 要求 |
|------|------|
| Hero | 封面 + 标题 + 讲师 + 价格/免费标签 |
| 侧栏/面板 | 购买/开始学习/试看 CTA 清晰可见 |
| 目录 | 章节树或列表，与 Stitch 层级一致 |

**行为不变**: 权限、试看、跳转 `/learn` 或 `/checkout` 沿用现有逻辑。

---

## 课节学习 `/learn/:courseId/:lessonId`

**文件**: `LessonView.vue`, `VideoPlayer.vue`

| 断点 | 布局 |
|------|------|
| ≥1024px | 目录 \| 正文/视频 \| 问答/笔记（三栏） |
| <768px | 单列；关键操作可达 |

**状态**: 加载骨架、无权限提示、课节完成/下一节。

---

## 个人中心 `/me/*`

**文件**: `StudentCenterView.vue`

| 项 | 要求 |
|----|------|
| Tab | URL 派生（`/me/learning` 等），非本地 ref |
| 侧栏 | 签到概览 + 导航项 |
| 内容区 | 各 tab 列表/表单与 Stitch 间距一致 |

---

## 学习地图 `/maps`, `/maps/:id`

**文件**: `MapListView.vue`, `MapDetailView.vue`

| 页面 | 要求 |
|------|------|
| 列表 | 卡片网格；封面或 stitch fallback 图 |
| 详情 | 时间轴/阶段步骤；CTA「开始探索」/「继续学习」文案与 spec 一致 |

---

## 结算 `/checkout/:courseId`

**文件**: `CheckoutView.vue`

| 项 | 要求 |
|----|------|
| 课程摘要 | 封面、标题、价格 |
| 支付方式 | 选中态清晰 |
| 提交 | 加载/错误/成功反馈不破坏布局 |

---

## 登录/注册 `/login`, `/register`

**文件**: `LoginRegisterView.vue`

| 项 | 要求 |
|----|------|
| 品牌区 | 与 Stitch 登录屏一致 |
| 表单 | 验证码刷新可见；错误贴近字段 |
| 布局 | `meta.hideFooter`；全屏认证壳 |

---

## 跨页一致性

| 检查项 | 标准 |
|--------|------|
| 横向滚动 | 375/768/1024/1280px 无意外 `overflow-x` |
| 夜读模式 | 文字与边框可读 |
| 空/错态 | 每页有明确反馈，无空白主内容区 |
| a11y | 保留 `aria-label`、键盘可操作控件 |
