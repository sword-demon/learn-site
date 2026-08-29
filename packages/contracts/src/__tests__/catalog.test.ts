import { describe, expect, it } from 'vitest';
import {
  CourseStatus,
  CourseDeletionResult,
  LessonDeliveryAssetDTO,
  PaginatedCategories,
  PaginatedCourses,
  PriceMode,
  PublicCourseDetailDTO,
} from '../catalog.js';
import { ApiResponse } from '../envelope.js';

describe('catalog', () => {
  it('accepts a media API URL without exposing an internal storage path', () => {
    const parsed = LessonDeliveryAssetDTO.parse({
      kind: 'video',
      asset_id: 12,
      media_url: '/api/media/assets/12',
      mime_type: 'video/mp4',
      size_bytes: 1024,
      status: 'ready',
    });

    expect(parsed.media_url).toBe('/api/media/assets/12');
    expect(parsed).not.toHaveProperty('storage_path');
  });

  it('CourseStatus stays enum-locked', () => {
    expect(CourseStatus.options).toEqual(['draft', 'published', 'unpublished']);
  });

  it('accepts only an acknowledged course deletion result', () => {
    expect(CourseDeletionResult.parse({ deleted: true })).toEqual({ deleted: true });
    expect(() => CourseDeletionResult.parse({ deleted: false })).toThrow();
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

  it('PublicCourseDetailDTO accepts legacy course detail without entitlement fields', () => {
    const parsed = ApiResponse(PublicCourseDetailDTO).parse({
      ok: true,
      data: {
        course: {
          id: 2402,
          category_id: 653,
          category_name: '实战课',
          title: 'dwqdwqdwq',
          cover_url: '/api/media/covers/example.png',
          teacher_name: 'dwqdwq',
          summary: 'dwqdwq',
          intro_html: '<p>课程介绍</p>',
          price_mode: 'free',
          list_price: 0,
          sale_price: 0,
          sale_start_at: null,
          sale_end_at: null,
          viewer_authorized: false,
          learner_count: 0,
          created_at: '2026-08-27 12:25:28',
        },
        chapters: [],
      },
    });

    expect(parsed.ok).toBe(true);
    if (parsed.ok) {
      expect(parsed.data.course.viewer_entitlement_status).toBeNull();
      expect(parsed.data.course.viewer_can_rejoin).toBe(false);
    }
  });
});
