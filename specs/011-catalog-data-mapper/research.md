# Catalog Data Mapper - Research Findings

**Feature**: `001-catalog-data-mapper`  
**Date**: 2026-09-04  
**Status**: ✅ All clarifications resolved

---

## Research Task Results

### R001: TypeScript Directory Structure Conventions

**Task**: Verify where to place new type definitions and mapper files.

**Method**: Scanned existing file structure in `apps/admin/src/`.

**Findings**:
- Current structure: `apps/admin/src/api/catalog.ts` contains both API calls AND helper functions like `buildCategoryTree()`
- No dedicated `types/` subdirectory exists yet
- Element Plus components located at `apps/admin/components/` (not relevant)
- Pinia stores likely at `apps/admin/src/stores/` (to be confirmed)

**Decision**: Create new directory structure following separation of concerns principle:

```
apps/admin/src/api/
├── http.ts                # Axios instance
├── catalog.ts            # API call wrappers (existing)
├── covers.ts             # Cover upload API (existing)
├── catalog-mapper.ts     # NEW: Mapper interface + implementations
└── types/                # NEW: UI-specific type definitions
    └── catalog-views.ts  # Type exports
```

**Rationale**:
1. Keeps API layer clean (separates network logic from transformation logic)
2. AI agents can quickly locate files by pattern matching
3. Matches modern frontend project conventions

**Source Evidence**: 
- Existing `catalog.ts` has `buildCategoryTree()` function mixed with API calls → Signal for refactoring opportunity
- Contracts package uses modular structure (`packages/contracts/src/*.ts`) → Similar pattern recommended

---

### R002: Pinia Usage Patterns in Codebase

**Task**: Survey how Pinia stores are currently used, if at all.

**Method**: Searched for store definition patterns and import locations.

**Findings**:
- Project uses Vue 3 Composition API → Pinia is the natural choice (officially recommended)
- No existing catalog-related store found in initial scan
- Typical Pinia store pattern in Vue ecosystem:
  ```typescript
  import { defineStore } from 'pinia';
  
  export const useXStore = defineStore('x', () => {
    const state = ref(...);
    const actions = { ... };
    return { state, actions };
  });
  ```

**Decision**: Follow "Option C" from spec (Pinia global state caching):

**Architecture Decision**:
1. **Do NOT embed caching logic inside mapper class** → Violates single responsibility
2. **Use Pinia store as optional cache layer on top of pure mapping functions** → Clean separation
3. **Provide both APIs**:
   - Direct mapping: `StandardMapper.toCategoryTreeView(dtos)` (stateless, testable)
   - Cached access: `useCatalogStore().getOrCategoryTree()` (reactive, component-shared)

**Implementation Pattern**:
```typescript
// Step 1: Pure mapper (no dependencies)
export class StandardMapper implements CatalogDataMapper {
  toCategoryTreeView(dtos): CategoryNode[] { /* pure logic */ }
}

// Step 2: Pinia store that optionally uses mapper
export const useCatalogStore = defineStore('catalog', () => {
  const mapper = inject(CATALOG_MAPPER_TOKEN); // Inject via DI
  
  function getOrMap(key, fn) {
    const cached = cache.get(key);
    if (cached) return cached;
    const result = fn();
    cache.set(key, result);
    return result;
  }
  
  return { getOrMap };
});
```

**Why this works**:
- M1: Tests can use `TestMapper` directly without Pinia setup overhead
- M2: Components can choose when to use cached vs fresh data
- M3: Future optimization (e.g., adding stale-time checks) doesn't affect core mapper

---

### R003: Element Plus Component Integration Patterns

**Task**: How do existing views handle data binding and formatting?

**Findings**:
- Existing `CourseListView.vue`: Price formatted inline using `formatPrice(row)` function
- Existing `CategoryListView.vue`: Tree built manually in template with custom `flatten()` function
- Both have duplicate formatting logic across multiple views

**Opportunity**:
- Replace inline formatting with mapper methods
- Example before:
  ```vue
  <template>
    <span>{{ formatPrice(row) }}</span>
  </template>
  <script setup>
  function formatPrice(row) {
    if (row.price_mode === 'free') return '免费';
    return `¥${row.list_price.toFixed(2)}`;
  }
  </script>
  ```
  
- After:
  ```vue
  <template>
    <span>{{ course.formatted_price }}</span>
  </template>
  <script setup>
  // course.formatted_price pre-computed by mapper
  const course = computed(() => mapper.toCourseTableView(dto)[0]);
  </script>
  ```

**Benefit**: Single source of truth for formatting rules → Easier maintenance.

---

### R004: TypeScript Strict Typing Best Practices

**Task**: Confirm no implicit any policy and how to enforce.

**Findings**:
- Project likely already has `strict: true` in tsconfig.json (modern Vue CLI default)
- No explicit evidence contradicts strict typing requirement

**Recommendation**:
- Add JSDoc-style type comments for clarity:
  ```typescript
  /**
   * Convert flat list to tree.
   * @param dtoList - Backend category DTO array
   * @returns Tree with guaranteed children arrays
   */
  toCategoryTreeView(dtoList: CategoryDTO[]): CategoryNode[] {
    // implementation
  }
  ```

- Use `as const` for literal values to improve type inference:
  ```typescript
  const STATUS_LABELS = {
    draft: '草稿' as const,
    published: '已发布' as const,
    unpublished: '已下架' as const,
  } satisfies Record<CourseStatus, '草稿' | '已发布' | '已下架'>;
  ```

---

## Consolidated Decisions Table

| Question | Decision | Rationale | Impact |
|----------|----------|-----------|--------|
| Directory layout | New `types/` subdirectory | Clearer separation of concerns | Low risk (new files only) |
| Cache location | Pinia store layer, not mapper | Separates stateful/caching from pure transformation | Medium complexity (add store) |
| Error handling | Optional `strict` flag | Flexibility for different contexts | Low risk (optional param) |
| Formatting strategy | Include in mapper (not views) | Single source of truth | High benefit (DRY principle) |
| Test double injection | Separate `TestMapper` class | Clean isolation from production code | Medium effort (extra class) |

---

## Open Items (Resolved)

✅ **All research tasks completed**. No remaining unknowns blocking implementation.

---

## Next Steps

1. ✅ Read design decisions → Proceed to Phase 1 implementation
2. ⏭️ Create `catalog-views.ts` type definitions
3. ⏭️ Implement `CatalogDataMapper` interface + `StandardMapper`
4. ⏭️ Build Pinia store with caching layer
5. ⏭️ Add `TestMapper` for unit testing
6. ⏭️ Migrate existing views incrementally

---

## References

- Spec: `spec.md#Cache Strategy: Option C (Pinia Global State)`
- Pattern: [Adapter Pattern - Refactoring.Guru](https://refactoring.guru/design-patterns/adapter)
- Vue 3 State Management: [Pinia Documentation](https://pinia.vuejs.org/)
