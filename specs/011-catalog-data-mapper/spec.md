# 011 Catalog Data Mapper - 管理端课程数据结构化

## Executive Summary

为提升 AI 可导航性和测试友好性，在管理端前端创建一个统一的数据映射层，负责将后端 DTO 转换为视图所需的结构化数据格式。

---

## User Scenarios & Testing

### Primary User Flow

1. **作为管理员**，我想查看课程列表
   - 系统展示格式化后的价格（如 "¥199.00"）
   - 系统显示状态标签（如 "已发布"）
   - 每门课程支持多种操作（编辑、预览、发布等）

2. **作为管理员**，我想管理分类树
   - 系统以树形结构展示分类（最多 3 层）
   - 每个分类节点包含子节点（即使为空数组）
   - 可以扁平化查看分类进行批量操作

3. **作为管理员**，我想编辑课程结构
   - 系统提供完整的课程树形数据（包含章节和课节）
   - 表单字段可直接绑定到数据结构
   - 新增/编辑章节后立即看到效果

### Edge Cases

- 后端返回空数据集时，前端显示空列表而非错误
- 部分可选字段缺失时，使用合理的默认值
- 大数据量时性能不受影响

---

## Functional Requirements

### FR-001: Category Tree Mapping

系统将分类列表转换为树形结构用于界面展示。

**Acceptance Criteria:**

- AC1: 所有 CategoryNode 必须包含 children 数组属性（即使为空 `[]`）
- AC2: 转换后保留原始 DTO 的所有字段（id, name, parent_id, depth 等）
- AC3: 支持将树形结构展平为线性列表用于表格展示
- AC4: 分类层级深度限制在 3 级以内

### FR-002: Course Table Mapping

系统将课程列表转换为表格友好的扁平结构。

**Acceptance Criteria:**

- AC1: 课程价格为人类可读格式（"免费"或"¥199.00"）
- AC2: 课程状态为中文标签（"草稿"/"已发布"/"已下架"）
- AC3: 状态类型标识正确（info/success/warning）
- AC4: 保留所有必要字段用于后续操作（编辑、删除、预览等）

### FR-003: Course Editor Form Mapping

系统将课程树形数据转换为编辑器可用的表单结构。

**Acceptance Criteria:**

- AC1: 课程信息字段可直接用于双向绑定
- AC2: 章节列表必须存在且至少为空数组
- AC3: 每个章节的课节列表必须存在且至少为空数组
- AC4: 章节和课节包含排序字段用于拖拽 UI

### FR-004: Price Formatting Utility

提供统一的课程价格格式化能力。

**Acceptance Criteria:**

- AC1: 免费课程返回"免费"字符串
- AC2: 付费课程显示标准价："¥199.00"
- AC3: 优惠价窗口内显示优惠价："¥99.00 / ¥199.00"
- AC4: 始终保留两位小数精度

### FR-005: Status Label Utilities

提供统一的状态显示别名能力。

**Acceptance Criteria:**

- AC1: 内部编码'draft'转换为中文'草稿'
- AC2: 内部编码'published'转换为中文'已发布'  
- AC3: 内部编码'unpublished'转换为中文'已下架'
- AC4: 提供对应的视觉类型标识（info/success/warning）

### FR-006: Test Double Support

支持测试场景下的固定数据映射。

**Acceptance Criteria:**

- AC1: 测试 mapper 可注入固定假数据
- AC2: 无需调用 API 即可完成单元测试
- AC3: 生产 mapper 与测试 mapper 接口一致

---

## Success Criteria

1. **AI Navigation**: 任何 AI agent 查看该模块代码时，能通过文件名和接口签名完全理解职责边界
2. **Testability**: 每个映射方法均可独立测试，无需 mock HTTP 请求
3. **Code Generation**: AI 生成新组件时，能自动应用正确的映射方法
4. **Type Safety**: 所有数据结构转换都有 TypeScript 类型检查覆盖
5. **Maintenance Reduction**: 减少视图组件中数据处理逻辑的重复代码 80% 以上

---

## Key Entities

| Entity | Description | Responsibility |
|--------|-------------|----------------|
| CatalogDataMapper | 主接口定义 | 规定所有映射方法的契约 |
| StandardMapper | 生产实现 | 实际调用 API 并转换数据 |
| TestMapper | 测试实现 | 返回固定假数据用于测试 |
| CategoryNode | 分类树节点 | 递归包含 children 数组 |
| CourseTableView | 课程表格行 | 扁平化的课程显示数据 |
| CourseEditorForm | 课程表单数据 | 完整的课程 + 章节 + 课节嵌套结构 |

---

## Assumptions

- A1: 后端合约定义在 `packages/contracts/src/catalog.ts` 中
- A2: 前端管理端目录为 `apps/admin/src/api/`
- A3: 需要分离 UI 类型与后端 DTO（新建 `types/catalog-views.ts`）
- A4: 格式化逻辑应包含在 Mapper 中（方案 B），而非视图组件中

---

## Dependencies & Constraints

### Blocking Edges

- T001: 需先确认后端 DTO 的完整结构（从 contracts 包读取）
- T002: 需了解哪些视图组件需要重构以使用新 API

### Non-Blocking Edges

- N1: UI 设计系统已存在（Element Plus）
- N2: TypeScript 类型系统已配置

---

## Open Questions

All questions have been resolved based on user decisions.

### Cache Strategy: Option C (Pinia Global State)

**Decision**: Use Pinia store for caching mapped data across components.

**Rationale**:
- C1: Multiple components may need the same course/category data (e.g., list view + detail + preview)
- C2: Avoids duplicate mapping operations when switching views
- C3: Enables reactive updates when underlying DTO changes
- C4: Maintains separation of concerns – mapping is pure, caching is stateful

**Implementation Pattern**:
```typescript
// In Pinia store
class CatalogStore {
  private cache = new Map<string, any>();
  
  getOrMap(key: string, dto: any): any {
    if (!this.cache.has(key)) {
      this.cache.set(key, StandardMapper.map(dto));
    }
    return this.cache.get(key);
  }
}
```

### Error Handling: Option C (Optional Strict Flag)

**Decision**: Provide optional `strict` parameter for flexible error handling strategy.

**Rationale**:
- E1: Different UI contexts may tolerate errors differently (list view vs. critical form)
- E2: Allows gradual migration from lax to strict validation
- E3: Debug mode can use strict=true for development environments

**Implementation Pattern**:
```typescript
interface MapOptions {
  strict?: boolean;
  logger?: (error: Error, context: string) => void;
}

function mapCourseTable(dtos: CourseDTO[], options: MapOptions = {}): CourseTableView[] {
  const { strict = false, logger } = options;
  
  return dtos.map(dto => {
    try {
      return validateAndTransform(dto);
    } catch (err) {
      if (strict) throw err;
      logger?.(err as Error, 'mapCourseTable');
      return defaultRow;
    }
  });
}
```

---

## Appendix

### Template References

- Design Pattern: Adapter Pattern (适配器模式)
- Testing Pattern: Test Double / Mock Object
- TypeScript Best Practice: Strict typing with no implicit any

### Glossary

- **DTO**: Data Transfer Object，后端传给前端的原始数据结构
- **Adapter**: 在此指将一种数据结构转换为另一种结构的中间层
- **Test Double**: 测试时的替代实现，返回假数据模拟真实行为
