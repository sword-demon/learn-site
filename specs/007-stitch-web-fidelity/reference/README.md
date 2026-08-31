# Stitch 参考屏幕归档

Stitch 项目：**拾阶学社 UI 设计系统**（ID `673993425689807844`）

## 用途

本目录存放从 Stitch MCP 导出的桌面端 HTML 参考，供视觉走查与 diff 对照。实现代码在 `apps/web/`，**不**直接嵌入此处 HTML。

## 导出方式（可选）

1. 使用 Stitch MCP `list_screens` 列出 `deviceType=DESKTOP` 屏幕
2. 对每个屏幕调用 `get_screen` 保存 HTML 至本目录，建议命名：`{screen-id}-{title}.html`

## 屏幕与路由映射

见 [contracts/page-surfaces.md](../contracts/page-surfaces.md) 与 [verification-walkthrough.md](../verification-walkthrough.md)。

## 注意

- 参考文案/图片不替换后端数据
- 首页主视觉以 `GET /api/learner/v1/home` 的 `banners` 为准，非 Stitch 静态 hero
