# UI 原型 (throwaway)

问题: 规格里的学习端 / 管理端信息架构看起来应该长什么样?

## 选定方案 (2026-08-23)

- **学习端 B 长卷学园**: 清新学园 + 校园网终端. 首页分类当章节铺开, 地图只在主导航.
- **管理端 A 深色侧栏**: Element Plus chalk (`#409EFF` / `#304156`), 常规 vue-element-admin 结构.

落选的 A/C (学习端) 与 B/C (管理端) 已从原型中移除. 实现 Vue 时按这两套折进 `apps/web` 与 `apps/admin`.

这不是 Vue 应用, 也不是 `$speckit-implement` 产物.

## 怎么开

```bash
sh scripts/prototype.sh
```

- 学习端: http://127.0.0.1:4173/apps/web/prototype/index.html
- 管理端: http://127.0.0.1:4173/apps/admin/prototype/index.html

内存态, 刷新即丢.
