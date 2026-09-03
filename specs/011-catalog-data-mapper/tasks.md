# Tasks: Catalog Data Mapper

**Input**: Design documents from `/specs/001-catalog-data-mapper/`

**Prerequisites**: 
- ✅ spec.md (user stories, functional requirements)
- ✅ plan.md (technical design, contracts, architecture)
- ✅ research.md (decisions, rationale)
- ✅ quickstart.md (validation scenarios)

**Tests**: NOT requested - skip unit/integration tests for now

**Organization**: Tasks organized by phase to enable independent implementation and testing of each component.

## Format: `[ID] [P?] [Story] Description`

- **[P]**: Can run in parallel (different files, no dependencies)
- **[Story]**: Which user story this task belongs to (e.g., US1, US2, US3)
- Include exact file paths in descriptions

## Path Conventions

- **Frontend app**: `apps/admin/src/` at workspace root
- **Types & API**: `apps/admin/src/api/`, `apps/admin/src/api/types/`
- **State management**: `apps/admin/src/stores/`

---

<!--
  ============================================================================
  IMPORTANT: These are REAL TASKS generated from the feature specification.
  
  Based on:
  - Functional Requirements FR-001 ~ FR-006 from spec.md
  - Technical Design from plan.md (interface contracts, data models)
  - Architectural Decisions from research.md
  
  Each phase is independently completable and testable via quickstart.md validation scenarios.
  ============================================================================
-->

## Phase 1: Setup (Project Initialization)

**Purpose**: Initialize TypeScript UI type definitions project structure

- [X] T001 Create directory structure per implementation plan (`apps/admin/src/api/types/`)
- [ ] T002 Verify existing Pinia store setup in `apps/admin/src/stores/`
- [ ] T003 Confirm contracts package exports in `packages/contracts/src/catalog.ts`

---

## Phase 2: Foundational (Blocking Prerequisites)

**Purpose**: Core infrastructure that MUST be complete before ANY user story can be implemented

**⚠️ CRITICAL**: No user story work can begin until this phase is complete

- [X] T004 Define base UI type interfaces (`apps/admin/src/api/types/catalog-views.ts`)
- [X] T005 Implement standard mapper utility methods (`apps/admin/src/api/catalog-mapper.ts`)
- [X] T006 Configure error handling options interface (`MapOptions` type with strict/logger)

**Checkpoint**: Foundation ready - mapping functionality foundation complete

---

## Phase 3: User Story 1 - Category Tree Mapping (Priority: P1) 🎯 MVP

**Goal**: Transform flat category lists into tree structures with guaranteed children arrays

**Independent Test**: Run scenario 1 from quickstart.md - validate all nodes have `children` arrays

### Tests for User Story 1 (OPTIONAL - skipped per spec) ⚠️

> **NOTE**: Unit tests deferred until after core implementation complete

### Implementation for User Story 1

- [X] T007 [P] [US1] Implement `toCategoryTreeView()` method in `apps/admin/src/api/catalog-mapper.ts`
- [X] T008 [P] [US1] Implement `toCategoryFlatList()` method in `apps/admin/src/api/catalog-mapper.ts`
- [X] T009 [US1] Implement `toCategoryEditorForm()` method in `apps/admin/src/api/catalog-mapper.ts`
- [ ] T010 [US1] Add depth validation helper function (max 3 levels)
- [ ] T008 [P] [US1] Implement `toCategoryFlatList()` method in `apps/admin/src/api/catalog-mapper.ts`
- [ ] T009 [US1] Implement `toCategoryEditorForm()` method in `apps/admin/src/api/catalog-mapper.ts`
- [ ] T010 [US1] Add depth validation helper function (max 3 levels)

**Checkpoint**: At this point, Category Tree Mapping should be fully functional and testable independently via quickstart.md scenario 1

---

## Phase 4: User Story 2 - Course Table View Transformation (Priority: P2)

**Goal**: Transform course DTOs into table-ready rows with pre-formatted price and status

**Independent Test**: Run scenario 2 and 3 from quickstart.md - validate price format and status labels

### Tests for User Story 2 (OPTIONAL - skipped per spec) ⚠️

> **NOTE**: Unit tests deferred until after core implementation complete

### Implementation for User Story 2

- [ ] T011 [P] [US2] Implement `toCourseTableView()` method in `apps/admin/src/api/catalog-mapper.ts`
  - Input: `CourseDTO[]` array
  - Output: `CourseTableView[]` with all pre-formatted fields
  - Integration: Calls `formatPrice()`, `statusLabel()`, `statusType()`
- [ ] T012 [P] [US2] Implement `toCoursePagination()` method in `apps/admin/src/api/catalog-mapper.ts`
  - Input: `PaginatedCourses` from API response
  - Output: `CoursePaginationView` with metadata + items
  - Preserve pagination info (total, page, limit)
- [ ] T013 [US2] Apply error handling with MapOptions support
  - Handle malformed courses gracefully when strict=false
  - Log non-fatal errors via optional logger callback
- [ ] T014 [US2] Update CourseListView.vue to use mapper (migration)
  - Replace inline `formatPrice(row)` function with mapper
  - Bind to `row.formatted_price` directly in template

**Checkpoint**: At this point, Course Table View transformation should be fully functional and testable independently via quickstart.md scenario 2 & 3

---

## Phase 5: User Story 3 - Course Editor Form (Priority: P3)

**Goal**: Transform complete course tree into editor form state with guaranteed nested arrays

**Independent Test**: Validate CourseEditView receives valid nested structure without undefined checks

### Tests for User Story 3 (OPTIONAL - skipped per spec) ⚠️

> **NOTE**: Unit tests deferred until after core implementation complete

### Implementation for User Story 3

- [ ] T015 [P] [US3] Implement `toCourseTreeForm()` method in `apps/admin/src/api/catalog-mapper.ts`
  - Input: `CourseTreeDTO` from backend (includes chapters + lessons)
  - Output: `CourseEditorForm` with all nested arrays guaranteed
  - Guarantee: `chapters: CourseChapterForm[]` never undefined
- [ ] T016 [P] [US3] Implement recursive chapter → Lesson transformation
  - Each chapter must include `lessons: CourseLessonForm[]` array
  - Copy content_type, asset_id, is_preview fields correctly
- [ ] T017 [US3] Handle course editing workflow (update vs create)
  - Preserve ID when updating existing course
  - Reset to empty states when creating new course
- [ ] T018 [US3] Update CourseEditView.vue to use mapper (migration)
  - Call `mapper.toCourseTreeForm(dto)` instead of manual conversion
  - Remove optional chain operators (`?.`) in favor of guaranteed properties

**Checkpoint**: At this point, Course Editor Form transformation should be fully functional and testable independently

---

## Phase 6: Pinia Store Integration (Cross-Cutting Enhancement)

**Goal**: Add reactive caching layer for mapped data across components

**Independent Test**: Verify cached data persists across component navigation

### Implementation

- [X] T019 [P] Create Pinia store structure in `apps/admin/src/stores/catalog.ts`
  - Define state with cache Map (`shallowReactive<Map<string, unknown>>`)
  - Export `useCatalogStore()` composable
- [X] T020 [P] Implement `getOrMap()` generic caching method
  - Check cache first before calling mapper function
  - Set cache with semantic key ('categoryTree', 'courseTable')
  - Return stored value or compute-once pattern
- [X] T021 [US] Inject StandardMapper into Pinia store via dependency injection
  - Allow switching between StandardMapper and TestMapper in tests
  - Provide default injection at app initialization
- [X] T022 Integrate cache layer into CategoryListView.vue
  - Replace direct `StandardMapper.toCategoryTreeView()` calls
  - Use `store.cacheAndMapCategoryTree(dtoList)` for cross-component sharing

**Checkpoint**: Cache layer provides reactive data sharing without duplicate computation

---

## Phase 7: Test Double Support (Testing Infrastructure)

**Goal**: Provide fixed data implementation for unit testing without HTTP calls

**Independent Test**: Verify TestMapper returns mock data without network requests

### Implementation

- [X] T023 [P] Define `MockCatalogData` interface in `catalog-views.ts`
  - Mock data structure matching real DTO patterns
  - Includes categories, courses, optional featured tree
- [X] T024 [P] Implement `TestMapper` class in `catalog-mapper.test.ts`
  - Implements same `CatalogDataMapper` interface as StandardMapper
  - Stores mock data internally
  - Returns mock data for all mapping methods
- [X] T025 [P] Implement `setMockData()` method for TestMapper
  - Allows runtime injection of custom test fixtures
  - Validates mock data structure before storage
- [ ] T026 Document TestMapper usage examples in quickstart.md scenario 4

**Checkpoint**: TestDouble support enables isolated unit testing without external dependencies

---

## Phase 8: Polish & Cross-Cutting Concerns

**Purpose**: Improvements affecting multiple user stories

- [ ] T027 [P] Add JSDoc comments to all mapper methods
  - Parameter descriptions
  - Return value descriptions
  - Invariant guarantees (I1-I5)
  - Error behavior documentation
- [ ] T028 [P] Create type export index in `apps/admin/src/api/types/index.ts`
  - Re-export all types for cleaner imports
  - Pattern: `export * from './catalog-views'`
- [ ] T029 [P] Run quickstart.md validation scenarios end-to-end
  - Scenario 1: Tree structure guarantee
  - Scenario 2: Price formatting
  - Scenario 3: Course table view
  - Scenario 4: Test double injection
  - Scenario 5: Error handling modes
- [ ] T030 Documentation update in README or ARCHITECTURE.md
  - Explain mapper pattern adoption
  - Reference catalog-mapper.ts as single source of truth
  - Link to quickstart.md for developers
- [ ] T031 Code review checklist
  - All tasks follow checklist format with IDs and file paths
  - Dependencies properly ordered
  - No vague tasks remaining
  - File paths match actual project structure

---

## Dependencies & Execution Order

### Phase Dependencies

- **Setup (Phase 1)**: No dependencies - can start immediately
- **Foundational (Phase 2)**: Depends on Setup completion - BLOCKS all user stories
- **User Stories (Phase 3-5)**: All depend on Foundational phase completion
  - Can proceed sequentially in priority order (P1 → P2 → P3)
  - Or parallel if team capacity allows (after Phase 2)
- **Pinia Integration (Phase 6)**: Depends on all user stories complete
- **Test Double (Phase 7)**: Depends on StandardMapper complete
- **Polish (Phase 8)**: Depends on all core features complete

### User Story Dependencies

- **User Story 1 (P1)**: Can start after Foundational (Phase 2) - No dependencies on other stories
- **User Story 2 (P2)**: Can start after Foundational (Phase 2) - Uses shared formatter utilities
- **User Story 3 (P3)**: Can start after Foundational (Phase 2) - Uses nested transformation logic from US2

### Within Each User Story

- Models/types before services/mappers
- Core mapping logic before integration
- Migration of real components last

### Parallel Opportunities

- All Setup tasks marked [P] can run in parallel
- All Foundational tasks marked [P] can run in parallel (within Phase 2)
- Once Foundational phase completes:
  - Developer A: US1 (Category Tree)
  - Developer B: US2 (Course Table)  
  - Developer C: US3 (Course Editor Form)
- All Test Double tasks [P] can execute in parallel

---

## Parallel Example: User Story 1

```bash
# Launch all Category-related mapping tasks together:
Task: "Implement toCategoryTreeView() method in apps/admin/src/api/catalog-mapper.ts"
Task: "Implement toCategoryFlatList() method in apps/admin/src/api/catalog-mapper.ts"
Task: "Implement toCategoryEditorForm() method in apps/admin/src/api/catalog-mapper.ts"

# Validation:
Task: "Run quickstart.md scenario 1 - Tree structure guarantee"
```

---

## Implementation Strategy

### MVP First (User Story 1 Only)

1. Complete Phase 1: Setup
2. Complete Phase 2: Foundational (CRITICAL - blocks all stories)
3. Complete Phase 3: User Story 1 (Category Tree Mapping)
4. **STOP and VALIDATE**: Run quickstart.md scenario 1
5. Deploy/demo if ready

### Incremental Delivery

1. Complete Setup + Foundational → Foundation ready
2. Add User Story 1 → Test independently → Deploy/Demo (MVP!)
3. Add User Story 2 → Test independently → Deploy/Demo
4. Add User Story 3 → Test independently → Deploy/Demo
5. Add Pinia Integration → Enhance cross-component experience
6. Add Test Double → Enable unit testing capability

### Parallel Team Strategy

With multiple developers:

1. Team completes Setup + Foundational together
2. Once Foundational is done:
   - Developer A: US1 (Category Tree)
   - Developer B: US2 (Course Table View)
   - Developer C: US3 (Course Editor Form)
3. All implement independently using shared foundation
4. Integrate Pinia + Test Double last
5. Polish together as final phase

---

## Notes

- [P] tasks = different files, no dependencies
- [Story] label maps task to specific user story for traceability
- Each user story is independently completable and testable
- Quickstart.md provides validation scenarios for each story
- Commit after each task or logical group
- Stop at any checkpoint to validate story independently via quickstart.md
- Avoid: vague tasks, missing file paths, cross-story dependencies breaking independence
- Total task count: 31 tasks (T001-T031)
- User Stories: 3 (P1: Category Tree, P2: Course Table, P3: Course Editor Form)
- Phases: 8 total (including polish)
- Parallel opportunities identified throughout

---

## Generated Artifacts Summary

| Artifact | Purpose | Location |
|----------|---------|----------|
| `catalog-views.ts` | UI type definitions | `apps/admin/src/api/types/` |
| `catalog-mapper.ts` | Interface + StandardMapper + TestMapper | `apps/admin/src/api/` |
| `catalog.ts` (Pinia) | Reactive caching store | `apps/admin/src/stores/` |
| **Migrated Views** | Use new mapper API | `CourseListView.vue`, `CategoryListView.vue`, `CourseEditView.vue` |

See [spec.md](./spec.md) for functional requirements.  
See [plan.md](./plan.md) for technical design.  
See [research.md](./research.md) for decisions and rationale.  
See [quickstart.md](./quickstart.md) for validation scenarios.
