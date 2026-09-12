import { describe, expect, it } from "vitest";
import {
  CourseDTO,
  CourseStatus,
  CourseDeletionResult,
  LessonDeliveryAssetDTO,
  PaginatedCategories,
  PaginatedCourses,
  PriceMode,
  PublicCourseDetailDTO,
} from "../catalog.js";
import { ApiResponse } from "../envelope.js";

describe("catalog", () => {
  it("accepts a media API URL without exposing an internal storage path", () => {
    const parsed = LessonDeliveryAssetDTO.parse({
      kind: "video",
      asset_id: 12,
      media_url: "/api/media/assets/12",
      mime_type: "video/mp4",
      size_bytes: 1024,
      status: "ready",
    });

    expect(parsed.media_url).toBe("/api/media/assets/12");
    expect(parsed).not.toHaveProperty("storage_path");
  });

  it("CourseStatus stays enum-locked", () => {
    expect(CourseStatus.options).toEqual(["draft", "published", "unpublished"]);
  });

  it("accepts only an acknowledged course deletion result", () => {
    expect(CourseDeletionResult.parse({ deleted: true })).toEqual({
      deleted: true,
    });
    expect(() => CourseDeletionResult.parse({ deleted: false })).toThrow();
  });

  it("PriceMode stays enum-locked", () => {
    expect(PriceMode.options).toEqual(["free", "paid"]);
  });

  it("CourseDTO requires a positive startup policy", () => {
    const base = {
      id: 12,
      department_id: 2,
      category_id: 3,
      title: "启动策略课程",
      cover_url: null,
      teacher_name: "林老师",
      summary: "课程启动策略",
      intro_rich_text: "<p>简介</p>",
      status: "draft",
      price_mode: "free",
      list_price: 0,
      sale_price: 0,
      sale_start_at: null,
      sale_end_at: null,
      idle_threshold_hours: 72,
      reminder_frequency_hours: 72,
      reminder_cap: 3,
      created_by_staff_id: 7,
      created_at: "2026-09-12 10:00:00",
      updated_at: "2026-09-12 10:00:00",
    };

    expect(CourseDTO.parse(base)).toMatchObject({
      idle_threshold_hours: 72,
      reminder_frequency_hours: 72,
      reminder_cap: 3,
    });
    expect(
      CourseDTO.safeParse({ ...base, idle_threshold_hours: 0 }).success,
    ).toBe(false);
    expect(
      CourseDTO.safeParse({ ...base, reminder_frequency_hours: -1 }).success,
    ).toBe(false);
    expect(CourseDTO.safeParse({ ...base, reminder_cap: 0 }).success).toBe(
      false,
    );
  });

  it("PaginatedCourses happy-path", () => {
    const result = PaginatedCourses.safeParse({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
    });
    expect(result.success).toBe(true);
  });

  it("PaginatedCategories rejects page=0", () => {
    expect(
      PaginatedCategories.safeParse({
        items: [],
        total: 0,
        page: 0,
        limit: 20,
      }).success,
    ).toBe(false);
  });

  it("PublicCourseDetailDTO accepts legacy course detail without entitlement fields", () => {
    const parsed = ApiResponse(PublicCourseDetailDTO).parse({
      ok: true,
      data: {
        course: {
          id: 2402,
          category_id: 653,
          category_name: "实战课",
          title: "dwqdwqdwq",
          cover_url: "/api/media/covers/example.png",
          teacher_name: "dwqdwq",
          summary: "dwqdwq",
          intro_html: "<p>课程介绍</p>",
          price_mode: "free",
          list_price: 0,
          sale_price: 0,
          sale_start_at: null,
          sale_end_at: null,
          viewer_authorized: false,
          learner_count: 0,
          created_at: "2026-08-27 12:25:28",
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
