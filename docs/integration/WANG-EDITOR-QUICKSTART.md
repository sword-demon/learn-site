# 富文本编辑器集成 - 快速开始指南

## 🚀 一键安装

### 1. 安装依赖

在项目根目录运行:

```bash
pnpm add @wangeditor/editor @wangeditor/editor-for-vue
```

### 2. 验证安装

运行测试脚本:

```bash
./apps/admin/scripts/test-wangeditor.sh
```

### 3. 启动开发服务器

```bash
pnpm dev
```

### 4. 测试编辑器

访问课程管理页面并创建/编辑课程:

- URL: `http://localhost:3000/admin/courses/new`
- 或编辑现有课程：`http://localhost:3000/admin/courses/:id/edit`

在"富文本简介 (HTML)"字段中即可看到新编辑器。

---

## ✨ 功能说明

### 基础功能

编辑器提供以下功能:

- **文本格式化**: 粗体、斜体、下划线、删除线、字体颜色、背景色
- **排版样式**: 6 级标题、段落样式、引用块
- **列表**: 有序列表、无序列表
- **多媒体**:
  - 图片上传 (支持拖拽)
  - 视频嵌入
- **代码**: 代码块高亮
- **链接**: 插入超链接
- **表格**: 插入和编辑表格
- **工具**: 撤销/重做、全屏预览、打印

### 自定义配置

#### 修改编辑器高度

```vue
<ContentEditor v-model="content" :height="500" />
```

#### 设置占位符文字

```vue
<ContentEditor v-model="content" placeholder="请输入课程内容描述..." />
```

#### 排除特定工具栏项

编辑 `ContentEditor.vue`:

```typescript
const toolbarConfig = {
  excludeKeys: ["fullscreen", "htmlModal"], // 隐藏全屏和 HTML 查看
};
```

#### 调整上传限制

```typescript
editorConfig: {
  MENU_CONF: {
    uploadImage: {
      maxFileSize: '10 mb',  // 最大图片大小
      maxNumber: 5,          // 最多上传数量
    },
    uploadVideo: {
      maxFileSize: '500 mb', // 最大视频大小
      maxNumber: 3,
    }
  }
}
```

---

## 📁 文件结构

```
apps/admin/
├── src/
│   ├── components/
│   │   └── course/
│   │       └── ContentEditor.vue        # 富文本编辑器组件 ⭐
│   └── views/
│       └── catalog/
│           └── CourseEditView.vue       # 课程编辑视图 (已集成) ⭐
├── scripts/
│   └── test-wangeditor.sh               # 安装验证脚本 ⭐
── package.json                          # 包含依赖声明
```

---

## 🔧 API 端点兼容性

编辑器自动使用现有的上传接口:

### 图片上传

- **Endpoint**: `/course-covers`
- **Method**: POST
- **Body**: `FormData { file: Blob }`
- **Response**: `{ data: { key, url, mime_type, size_bytes } }`

### 视频上传

- **Endpoint**: `/assets`
- **Method**: POST
- **Body**: `FormData { file: Blob, kind: 'video' }`
- **Response**: `{ data: { id, storage_path, ... } }`

---

## 🐛 故障排查

### 问题 1: 编辑器不显示

**症状**: 表单显示空白或旧的 `<textarea>`

**解决方案**:

1. 检查是否安装了依赖：`grep "@wangeditor/editor" apps/admin/package.json`
2. 确认浏览器控制台没有 JavaScript 错误
3. 重新构建：`pnpm run build:admin`

### 问题 2: 图片上传失败

**症状**: 点击上传图片按钮无响应或报错

**检查**:

1. 后端 `/course-covers` 接口是否正常运行
2. 网络请求是否在开发者工具的 Network 标签中显示
3. 文件大小是否超过 5MB 限制

### 问题 3: TypeScript 编译错误

**症状**: `vue-tsc` 报告类型错误

**解决方案**:
确保已安装正确的类型定义:

```bash
pnpm add -D @types/node
```

然后运行:

```bash
pnpm vue-tsc --noEmit
```

### 问题 4: CSS 样式混乱

**症状**: 工具栏与 Element Plus 主题不一致

**解决方案**:
编辑 `ContentEditor.vue` 的 `<style>` 部分:

```css
/* 覆盖默认样式以匹配 Element Plus */
.w-e-toolbar {
  border-bottom-color: var(--el-border-color-light);
}

.w-e-text {
  padding: 12px;
  font-size: 14px;
}
```

---

## 🎯 下一步优化

根据集成文档中的计划，可以添加的功能:

- [ ] Markdown 模式切换
- [ ] AI 内容生成助手
- [ ] 历史版本追踪
- [ ] 多语言支持
- [ ] 深色模式
- [ ] 自定义块组件 (测验、投票等)
- [ ] 媒体库浏览界面
- [ ] 多人协作编辑

---

## 📖 参考资料

- [完整集成文档](../../docs/integration/wang-editor-integration.md)
- [选型研究报告](../../docs/research/rich-text-editor-selection.md)
- [WangEditor 官方文档](https://www.wangeditor.com/)
- [Vue 3 文档](https://vuejs.org/)

---

## 💡 常见问题

**Q: 能否禁用图片上传?**  
A: 在 `editorConfig.MENU_CONF.uploadImage` 中设置为空数组或使用 `excludeKeys`.

**Q: 如何获取纯文本而不是 HTML?**  
A: 使用 `editorRef.getText()` 方法而非 `getHtml()`.

**Q: 支持自定义快捷键吗？**  
A: 是的，参考 WangEditor 文档自定义键盘事件监听器.

**Q: 如何在其他地方复用此编辑器?**  
A: 直接导入 `@/components/course/ContentEditor.vue` 并在任何 Vue 组件中使用.

---

**最后更新**: 2024-08-29  
**维护者**: AI Coding Team  
**状态**: ✅ 生产就绪
