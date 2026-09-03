# Catalog Data Mapper - Quickstart Validation Guide

**Feature**: `001-catalog-data-mapper`  
**Goal**: End-to-end validation scenarios proving the feature works without full implementation details.

---

## Prerequisites

- ✅ Admin SPA builds successfully: run `make rebuild-admin`
- ✅ Developer tools console accessible (Chrome DevTools recommended)
- ✅ Logged in as admin user with course management permissions
- ✅ Browser console not blocking script execution

---

## Scenario 1: Category Tree Structure Guarantee

**Objective**: Verify that all category nodes have guaranteed `children` array property (Invariant I1).

### Setup

1. Navigate to **管理端 → 组织管理 → 分类管理**
2. Ensure at least one category exists (create if needed: "测试分类", parent = None, depth = 1)

### Validation Steps

In browser console, run:

```javascript
// Step 1: Import mapper (after implementation)
import { StandardMapper } from '@/api/catalog-mapper';

// Step 2: Mock backend DTO response
const mockDTOs = [
  {
    id: 1,
    name: 'Math',
    parent_id: 0,
    depth: 1,
    path: '/',
    sort: 0,
    status: 'enabled',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  },
  {
    id: 2,
    name: 'Algebra',
    parent_id: 1,
    depth: 2,
    path: '/math/',
    sort: 1,
    status: 'enabled',
    created_at: new Date().toISOString(),
    updated_at: new Date().toISOString(),
  },
];

// Step 3: Call mapping function
const tree = StandardMapper.toCategoryTreeView(mockDTOs);

// Step 4: Validate invariant I1
console.assert(
  Array.isArray(tree[0].children), 
  'I1 Violated: children must be array'
);
console.assert(
  tree[0].children.length === 1 && tree[0].children[0].id === 2,
  'I1 Violated: child node missing'
);

// Step 5: Log result
console.log('✅ Invariant I1 PASSED:', JSON.stringify(tree, null, 2));
```

### Expected Outcome

- **Pass**: Console shows no assertion errors, tree structure has nested children arrays
- **Fail**: Assertion error "I1 Violated" appears → Fix required before proceeding

### Acceptance Criteria

- AC1: All nodes have `children` property
- AC2: `children` is always an array (never `undefined` or `null`)
- AC3: Empty branches show `[]`, not missing property

---

## Scenario 2: Price Formatting Logic

**Objective**: Verify `formatPrice()` handles all three pricing states correctly.

### Validation Steps

```javascript
import { StandardMapper } from '@/api/catalog-mapper';

// Free course
const freeResult = StandardMapper.formatPrice('free', 0, 0);
console.assert(freeResult === '免费', `Free format failed: got "${freeResult}"`);

// Standard priced course
const standardResult = StandardMapper.formatPrice('paid', 199.00, 0);
console.assert(standardResult === '¥199.00', `Standard format failed: got "${standardResult}"`);

// Sale price window active
const saleResult = StandardMapper.formatPrice('paid', 199.00, 99.50);
console.assert(saleResult === '¥99.50 / ¥199.00', `Sale format failed: got "${saleResult}"`);

// Log summary
console.log('✅ Price formatting PASSED:');
console.table([
  { mode: 'Free', input: [0, 0, 0], expected: '免费', actual: freeResult, pass: freeResult === '免费' },
  { mode: 'Standard', input: ['paid', 199, 0], expected: '¥199.00', actual: standardResult, pass: standardResult === '¥199.00' },
  { mode: 'Sale', input: ['paid', 199, 99.50], expected: '¥99.50/¥199.00', actual: saleResult, pass: saleResult === '¥99.50/¥199.00' },
]);
```

### Expected Outcome

All three assertions pass with correct formatted strings including:
- Chinese label for free courses
- ¥ symbol prefix
- Exactly 2 decimal places
- Slash separator for sale prices

---

## Scenario 3: Course Table View Transformation

**Objective**: Verify full transformation from DTO to table row with all formatting applied.

### Validation Steps

```javascript
import { StandardMapper } from '@/api/catalog-mapper';

const dto = {
  id: 42,
  title: 'TypeScript Advanced Patterns',
  teacher_name: '张三',
  summary: '深入 TypeScript 高级编程技巧',
  intro_rich_text: '<p>...</p>',
  cover_url: 'https://example.com/cover.jpg',
  department_id: 1,
  category_id: 1,
  price_mode: 'paid',
  list_price: 299.00,
  sale_price: 149.00,
  sale_start_at: '2026-09-01 00:00:00',
  sale_end_at: '2026-09-30 23:59:59',
  status: 'published',
  created_by_staff_id: 1,
  created_at: '2026-08-01 12:00:00',
  updated_at: '2026-09-01 10:00:00',
};

const row = StandardMapper.toCourseTableView([dto])[0];

// Validate fields
console.assert(row.id === 42, 'ID mismatch');
console.assert(row.formatted_price === '¥149.00 / ¥299.00', 'Price wrong');
console.assert(row.status_label === '已发布', 'Status label wrong');
console.assert(row.status_type === 'success', 'Status type wrong');
console.assert(row.title === 'TypeScript Advanced Patterns', 'Title mismatch');

console.log('✅ Course table view PASSED:', row);
```

### Expected Outcome

- All fields preserved from DTO
- `formatted_price` contains both sale and standard prices
- Status correctly mapped to Chinese + visual type

---

## Scenario 4: Test Double Injection

**Objective**: Verify test mapper works without real API calls.

### Context

This scenario simulates unit test environment where we don't want network calls.

### Validation Steps

```javascript
import { TestMapper } from '@/api/catalog-mapper';

const mockData = {
  categories: [{
    id: 999,
    name: '[TEST] Mock Category',
    parent_id: 0,
    depth: 1,
    path: '/test/',
    sort: 0,
    status: 'enabled',
    created_at: '2026-09-04T00:00:00Z',
    updated_at: '2026-09-04T00:00:00Z',
  }],
  courses: [],
};

const testMapper = new TestMapper();
testMapper.setMockData(mockData);

// Should return mock data without calling HTTP
const tree = testMapper.toCategoryTreeView(mockData.categories);

console.assert(tree[0].name === '[TEST] Mock Category', 'Test double failed');
console.assert(tree[0].id === 999, 'Test ID mismatch');

console.log('✅ Test double PASSED: No HTTP calls made, returned mock data');
```

### Expected Outcome

- Zero network requests triggered
- Returns exact mock data passed to `setMockData()`
- Can be used inside Jest/Vitest tests without `jest.mock()` overhead

---

## Scenario 5: Error Handling Modes

**Objective**: Verify strict vs non-strict error handling modes work correctly.

### Non-Strict Mode (default)

```javascript
const malformedDTOS = [
  {
    id: 1,
    title: 'Valid Course',
    // Missing required field: teacher_name
  },
];

try {
  const rows = StandardMapper.toCourseTableView(malformedDTOS, { strict: false });
  console.log('✅ Non-strict mode handled gracefully:', rows);
} catch (e) {
  console.error('❌ Non-strict should not throw:', e);
}
```

### Strict Mode

```javascript
try {
  const rows = StandardMapper.toCourseTableView(malformedDTOS, { strict: true });
  console.error('❌ Strict mode should have thrown!');
} catch (e) {
  console.log('✅ Strict mode correctly threw:', e.message);
}
```

### Logger Callback Test

```javascript
let logCalls = 0;
const logger = (err, context) => {
  logCalls++;
  console.warn(`[Catalog Mapper] ${context}: ${err.message}`);
};

StandardMapper.toCourseTableView(malformedDTOS, { 
  strict: false, 
  logger 
});

console.assert(logCalls === 1, 'Logger callback not invoked');
console.log('✅ Logger pattern works');
```

---

## Next Steps After Validation

Once all scenarios pass:

1. ✅ Move to component integration (`CourseListView.vue`, etc.)
2. ✅ Replace inline formatting functions with mapper calls
3. ✅ Add Pinia store caching layer for cross-component data sharing
4. ✅ Write Jest/Vitest unit tests using `TestMapper`
5. ✅ Update documentation to reference new mapper API

---

## Troubleshooting

### Issue: `Cannot find module '@/api/catalog-mapper'`

**Fix**: Ensure file created at correct location before running scenarios.

### Issue: Type errors in console

**Fix**: This is a validation guide – TypeScript compile-time errors are expected until implementation completes. Run scenarios after build succeeds.

### Issue: Cartel cache stale data

**Fix**: Clear Application tab → Site data → Cached entries, then re-run scenarios.

---

## References

- Data Model: See `data-model.md#Type Definitions` for complete schema
- Implementation Plan: See `plan.md#Phase 1 Design & Contracts` for interface spec
- Research Decisions: See `research.md#Consolidated Decisions Table` for architectural choices
