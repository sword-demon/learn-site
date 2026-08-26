import { describe, expect, it } from 'vitest';
import { CourseStatus, PaginatedCategories, PaginatedCourses, PriceMode } from '../catalog.js';

describe('catalog', () => {
  it('CourseStatus stays enum-locked', () => {
    expect(CourseStatus.options).toEqual(['draft', 'published', 'unpublished']);
  });

  it('PriceMode stays enum-locked', () => {
    expect(PriceMode.options).toEqual(['free', 'paid']);
  });

  it('PaginatedCourses happy-path', () => {
    const result = PaginatedCourses.safeParse({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
    });
    expect(result.success).toBe(true);
  });

  it('PaginatedCategories rejects page=0', () => {
    expect(
      PaginatedCategories.safeParse({
        items: [],
        total: 0,
        page: 0,
        limit: 20,
      }).success,
    ).toBe(false);
  });
});