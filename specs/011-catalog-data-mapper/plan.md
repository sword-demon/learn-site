# Catalog Data Mapper - Technical Plan

## Technical Context

This feature implements a data mapping layer for the admin frontend to convert backend DTOs into view-friendly structures.

### Architecture Stack

- **Frontend Framework**: Vue 3 + TypeScript + Element Plus
- **State Management**: Pinia (for caching strategy)
- **Type System**: Strict TypeScript with no implicit any
- **Contract Source**: `packages/contracts/src/catalog.ts` (Zod schemas)
- **API Layer**: Axios instance at `apps/admin/src/api/http.ts`

### Known Unknowns

[NEEDS CLARIFICATION: What is the exact directory structure under `apps/admin/src/api/`?]
- Need to verify if existing files like `catalog.ts`, `covers.ts` are the target location for new mapper files
- Need to confirm if there's an existing `types/` subdirectory or should create one

[NEEDS CLARIFICATION: Does Pinia store already exist for catalog-related state?]
- Need to check if we should add to an existing store or create a new `CatalogStore`
- Need to understand current API response caching patterns (if any)

---

## Constitution Check

Review against `.specify/memory/constitution.md`:

**If constitution exists, evaluate each principle:**
- [ ] Principle 1: ...
- [ ] Principle 2: ...
- [ ] ...

**Result**: ✅ No violations found (or list violations)

---

## Phase 0: Research & Clarifications

### Research Tasks

**Task R001**: Verify TypeScript directory conventions
**Source**: `[NEEDS CLARIFICATION]` in Technical Context
**Status**: In progress...

**Task R002**: Survey Pinia usage patterns in codebase
**Source**: `[NEEDS CLARIFICATION]` in Technical Context  
**Status**: In progress...

### Findings

#### Decision 1: Directory Structure

**Decision**: Create `apps/admin/src/api/types/` subdirectory for UI-specific types.

**Rationale**:
- D1: Separates concerns between backend DTOs and frontend views
- D2: Matches the assumption A3 from spec.md
- D3: Easier for AI agents to locate type definitions by filename pattern

**Implementation**: 
```
apps/admin/src/api/
├── catalog.ts           # API calls (existing)
├── catalog-mapper.ts    # New: Standard mapper implementation
├── catalog-views.ts     # New: UI type definitions
└── types/               # New: Shared type utilities
    └── index.ts         # Export all type definitions
```

#### Decision 2: Pinia Store Integration

**Decision**: Add caching logic as an optional layer on top of Pinia store.

**Rationale**:
- R1: Not all components need cached mapped data
- R2: Keep pure mapping functions separate from state management
- R3: Provide both direct mapping API and cached store API

**Implementation Pattern**:
```typescript
// apps/admin/src/stores/catalog.ts
export const useCatalogStore = defineStore('catalog', () => {
  const cache = ref<Map<string, unknown>>(new Map());
  
  function getOrMap<T>(key: string, dto: unknown, mapperFn: (d: unknown) => T): T {
    const cached = cache.value.get(key);
    if (cached) return cached as T;
    
    const mapped = mapperFn(dto);
    cache.value.set(key, mapped);
    return mapped;
  }
  
  // ... rest of store logic
});
```

**Alternatives Considered**:
- A1: Embed caching directly in mapper class → Rejected (violates single responsibility)
- A2: Use React Query-like pattern → Rejected (project uses Pinia, not TanStack Query)

---

## Phase 1: Design & Contracts

### 1. Data Model (catalog-views.ts)

#### Type Definitions

```typescript
/**
 * UI-specific types for catalog views.
 * These extend or reshape backend DTOs for frontend consumption.
 */

// ─── Category View Types ───────────────────────────────────────

/**
 * Category tree node with recursive children guarantee.
 * I1: children ALWAYS exists, even when empty [].
 */
export interface CategoryNode extends CategoryDTO {
  children: CategoryNode[];  // Not optional!
}

/**
 * Flattened category row with depth info for table display.
 */
export interface FlatCategoryRow extends CategoryDTO {
  depth: number;  // Explicit depth field
}

/**
 * Form input shape for category create/edit dialogs.
 */
export interface CategoryFormData {
  id: number | null;
  parent_id: number | null;
  name: string;
  sort: number;
}

// ─── Course View Types ─────────────────────────────────────────

/**
 * Row data for course table/list view.
 * F2: Pre-formatted price and status strings.
 */
export interface CourseTableView {
  id: number;
  title: string;
  teacher_name: string;
  formatted_price: string;      // "免费" or "¥199.00" or "¥99.00 / ¥199.00"
  status_label: '草稿' | '已发布' | '已下架';
  status_type: 'info' | 'success' | 'warning';
  created_at: string;
  updated_at: string;
  // ... all other fields needed for operations
}

/**
 * Complete course editor form state.
 * F3: Chapter lessons array NEVER undefined.
 */
export interface CourseEditorForm {
  // Course core fields
  id?: number;
  title: string;
  department_id: number;
  category_id: number;
  cover_url?: string | null;
  teacher_name: string;
  summary: string;
  intro_rich_text: string;
  price_mode: 'free' | 'paid';
  list_price: number;
  sale_price?: number;
  sale_start_at?: string | null;
  sale_end_at?: string | null;
  
  // Children guarantees
  chapters: CourseChapterForm[];  // Always array, never undefined
}

export interface CourseChapterForm {
  id?: number;
  course_id: number;
  title: string;
  sort: number;
  status: 'enabled' | 'disabled';
  lessons: CourseLessonForm[];  // Always array, never undefined
}

export interface CourseLessonForm {
  id?: number;
  chapter_id: number;
  title: string;
  sort: number;
  status: 'enabled' | 'disabled';
  content_type: 'markdown' | 'pdf' | 'video';
  body_markdown?: string | null;
  asset_id?: number | null;
  is_preview: boolean;
  duration_seconds: number;
}

// ─── Mapping Options ───────────────────────────────────────────

/**
 * Optional error handling configuration.
 */
export interface MapOptions {
  /**
   * Strict mode throws on malformed data instead of using defaults.
   * @default false
   */
  strict?: boolean;
  
  /**
   * Optional logger for non-fatal mapping errors.
   * Used when strict=false to avoid silent failures in production.
   */
  logger?: (error: Error, context: string) => void;
}

// ─── Test Double Types ─────────────────────────────────────────

/**
 * Mock data generator for unit tests.
 * Does NOT call real APIs.
 */
export interface MockCatalogData {
  categories: CategoryDTO[];
  courses: CourseDTO[];
  featuredCourse?: CourseTreeDTO;
}
```

**Validation Rules**:
- VR1: All arrays must be initialized (never `undefined`)
- VR2: Price fields must be non-negative numbers
- VR3: Status enums must match backend enum values exactly
- VR4: Depth field must be ≤ 3 for categories

---

### 2. Interface Contracts

#### Contract C001: CatalogDataMapper Interface

**Location**: `apps/admin/src/api/catalog-mapper.ts`

**Purpose**: Define the adapter pattern interface for all catalog data transformations.

```typescript
import type { 
  CategoryDTO, 
  CourseDTO, 
  CourseTreeDTO,
  PaginatedCourses,
  PriceMode,
  CourseStatus,
} from '@learn-site/contracts';

import type {
  CategoryNode,
  FlatCategoryRow,
  CategoryFormData,
  CourseTableView,
  CourseEditorForm,
  CoursePaginationView,
  MapOptions,
} from './types/catalog-views';

/**
 * CatalogDataMapper — Unified data transformation layer.
 * 
 * Invariants:
 *   I1: CategoryNode.children ALWAYS exists as array (minimum [])
 *   I2: CourseTableView.formatted_price is human-readable string
 *   I3: CourseEditorForm.chapters/lessons arrays never undefined
 *   I4: All status labels are Chinese display strings
 *   I5: All price formats include ¥ symbol and 2 decimal places
 * 
 * Two Adapter Evidence:
 *   A) StandardMapper - Production implementation calling real DTOs
 *   B) TestMapper - Unit test implementation returning fixed data
 */
export interface CatalogDataMapper {
  // ─── Category transformations ────────────────────────────────
  
  /**
   * Convert flat category list to tree structure.
   * Ensures I1: every node has children array.
   */
  toCategoryTreeView(dtoList: CategoryDTO[]): CategoryNode[];
  
  /**
   * Flatten tree to linear rows with explicit depth.
   * Useful for table views requiring indented rows.
   */
  toCategoryFlatList(nodes: CategoryNode[]): FlatCategoryRow[];
  
  /**
   * Prepare form data from existing DTO or empty state.
   */
  toCategoryEditorForm(dto?: CategoryDTO | null): CategoryFormData;
  
  // ─── Course transformations ──────────────────────────────────
  
  /**
   * Transform course list for table display.
   * Ensures I2, I4, I5 formatting rules.
   */
  toCourseTableView(dtoList: CourseDTO[], options?: MapOptions): CourseTableView[];
  
  /**
   * Convert course tree to editor form state.
   * Ensures I3: nested arrays always present.
   */
  toCourseTreeForm(tree: CourseTreeDTO): CourseEditorForm;
  
  /**
   * Wrap paginated courses with pagination metadata.
   */
  toCoursePagination(paginated: PaginatedCourses): CoursePaginationView;
  
  // ─── Formatting utilities ────────────────────────────────────
  
  /**
   * Format price according to I5.
   */
  formatPrice(price_mode: PriceMode, list_price: number, sale_price?: number): string;
  
  /**
   * Internal status code to display label.
   */
  statusLabel(status: CourseStatus): '草稿' | '已发布' | '已下架';
  
  /**
   * Get visual type tag for status.
   */
  statusType(status: CourseStatus): 'info' | 'success' | 'warning';
  
  // ─── Test double only ────────────────────────────────────────
  
  /**
   * (TestDouble interface extension) Set fixed mock data.
   * Only available on TestMapper instance.
   */
  setMockData(mock: MockCatalogData): void;
}
```

**Acceptance Criteria**:
- AC1: Interface can be implemented by multiple adapters
- AC2: All methods have clear parameter and return types
- AC3: Optional parameters documented with default behavior
- AC4: Test double extension clearly marked as non-production

#### Contract C002: Pinia Store Cache Layer

**Location**: `apps/admin/src/stores/catalog.ts`

**Purpose**: Provide reactive caching for mapped data across components.

```typescript
import { ref, shallowReactive } from 'vue';
import { defineStore } from 'pinia';
import type { CatalogDataMapper } from '@/api/catalog-mapper';

interface CatalogCacheItem<T> {
  data: T;
  timestamp: number;
}

export interface CatalogStoreState {
  categoryTree: CategoryNode[] | null;
  courseTable: CourseTableView[] | null;
  courseEditor: CourseEditorForm | null;
  lastUpdate: Record<string, number>;
}

export const useCatalogStore = defineStore('catalog', () => {
  // State
  const cache = shallowReactive<Map<string, CatalogCacheItem<unknown>>>(new Map());
  const mapper = ref<CatalogDataMapper | null>(null);
  
  // Actions
  function setMapper(m: CatalogDataMapper): void {
    mapper.value = m;
  }
  
  function getOrCategoryTree(): CategoryNode[] {
    if (!mapper.value || !cache.value.has('categoryTree')) {
      throw new Error('Missing cached data or mapper');
    }
    return cache.value.get('categoryTree')!.data as CategoryNode[];
  }
  
  function cacheAndMapCategoryTree(dtoList: CategoryDTO[]): CategoryNode[] {
    const key = 'categoryTree';
    const mapped = mapper.value!.toCategoryTreeView(dtoList);
    cache.value.set(key, { data: mapped, timestamp: Date.now() });
    return mapped;
  }
  
  // ... more cache methods
  
  return {
    setMapper,
    getOrCategoryTree,
    cacheAndMapCategoryTree,
    // ... more getters/setters
  };
});
```

**Design Decisions**:
- DD1: Use `shallowReactive` for performance (only observe top-level Map)
- DD2: Cache key by semantic name ('categoryTree', 'courseTable')
- DD3: Store timestamp for potential stale-data invalidation later

---

### 3. Quickstart Validation Guide

**Location**: `specs/001-catalog-data-mapper/quickstart.md` (to be created)

```markdown
# Catalog Data Mapper - Quickstart Validation

## Prerequisites

- Admin SPA builds successfully: `make rebuild-admin`
- Developer tools console accessible
- Test user logged in with admin role

---

## Scenario 1: Basic Tree Mapping

**Goal**: Verify `toCategoryTreeView()` produces valid tree with children arrays.

**Steps**:
1. Open browser console in admin panel
2. Run:
   ```javascript
   import { StandardMapper } from '@/api/catalog-mapper';
   const dtos = [{id: 1, name: 'Math', parent_id: 0, depth: 1, children: []}];
   const tree = StandardMapper.toCategoryTreeView(dtos);
   console.assert(tree[0].children !== undefined, 'I1 violated');
   console.log('✓ Tree structure valid:', tree);
   ```

**Expected Outcome**: Children array exists, no errors thrown.

---

## Scenario 2: Price Formatting

**Goal**: Verify `formatPrice()` handles all price modes correctly.

**Steps**:
1. Console input:
   ```javascript
   const free = StandardMapper.formatPrice('free', 0, 0);
   const standard = StandardMapper.formatPrice('paid', 199, 0);
   const sale = StandardMapper.formatPrice('paid', 199, 99);
   
   console.assert(free === '免费', 'Free format wrong');
   console.assert(standard === '¥199.00', 'Standard format wrong');
   console.assert(sale === '¥99.00 / ¥199.00', 'Sale format wrong');
   console.log('✓ Price formatting valid');
   ```

**Expected Outcome**: Three assertions pass, log message shows.

---

## Scenario 3: Test Double Injection

**Goal**: Verify test mapper works without real API calls.

**Steps**:
1. In test file (after implementation):
   ```typescript
   import { TestMapper } from '@/api/catalog-mapper';
   import type { MockCatalogData } from '@/api/types/catalog-views';
   
   const mock: MockCatalogData = {
     categories: [{id: 1, name: 'Demo', parent_id: 0, depth: 1, path: '/', sort: 0, status: 'enabled', created_at: '', updated_at: ''}],
     courses: [],
   };
   
   const mapper = new TestMapper();
   mapper.setMockData(mock);
   const tree = mapper.toCategoryTreeView([]); // Should return mock data
   ```

**Expected Outcome**: Returns mock data without HTTP requests.

---

## Next Steps

After validation passes:
1. Replace manual instantiation with Pinia store injection
2. Integrate into actual Vue components (`CourseListView.vue`, etc.)
3. Remove hardcoded mapper references
4. Write component unit tests using `TestMapper`

See `data-model.md` for detailed type specifications.
See `research.md` for architectural decisions.
```

---

## Implementation Phases Summary

### Phase 1: Core Types & Interfaces
- [ ] Create `apps/admin/src/api/types/catalog-views.ts`
- [ ] Define `CatalogDataMapper` interface in `catalog-mapper.ts`
- [ ] Implement `StandardMapper` concrete class

### Phase 2: Pinia Integration  
- [ ] Create `apps/admin/src/stores/catalog.ts`
- [ ] Add cache layer actions
- [ ] Export convenience helper functions

### Phase 3: Test Doubles
- [ ] Implement `TestMapper` class
- [ ] Define `MockCatalogData` generator helpers
- [ ] Write unit tests for mapper methods

### Phase 4: Component Migration
- [ ] Update `CourseListView.vue` to use mapper
- [ ] Update `CategoryListView.vue` to use mapper
- [ ] Update `CourseEditView.vue` to use mapper
- [ ] Verify no regressions in existing functionality

---

## Dependencies Required

| Dependency | Location | Purpose |
|------------|----------|---------|
| `@learn-site/contracts` | `packages/contracts/` | Backend DTO type definitions |
| Pinia | `apps/admin/node_modules/` | State management for cache |
| TypeScript | Skipped | Strict typing enforcement |

---

## Risk Assessment

**High Risk Items**:
1. **Performance**: Large catalog datasets (>1000 items) may cause mapping lag → Mitigation: Add virtual scrolling first
2. **Breaking Changes**: Existing components may break during migration → Mitigation: Feature flag, gradual rollout
3. **Type Mismatches**: Backend DTO evolution → Frontend breaks → Mitigation: Zod runtime validation layer

**Mitigation Strategy**:
- M1: Start with read-only views (list/table), then edit forms
- M2: Use TypeScript compiler errors to catch mismatches early
- M3: Add integration tests for critical user flows

---

## Appendix

### References

- Spec: `specs/001-catalog-data-mapper/spec.md`
- Contracts: `packages/contracts/src/catalog.ts`
- Existing Views: `apps/admin/src/views/catalog/`
- API Layer: `apps/admin/src/api/catalog.ts`

### Glossary

- **Adapter Pattern**: Structural pattern allowing incompatible interfaces to work together
- **Test Double**: Generic term for fake objects used in testing (mock, stub, fake)
- **Pinia**: Official state management library for Vue 3
