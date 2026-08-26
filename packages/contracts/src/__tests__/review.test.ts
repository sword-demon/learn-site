import { describe, expect, it } from "vitest";
import * as reviewContracts from "../review.js";
import { ReviewListDTO, ReviewReplyDTO, ReviewThreadDTO } from "../review.js";

describe("review contracts", () => {
  it("accepts a public thread without exposing internal account ids", () => {
    const parsed = ReviewThreadDTO.parse({
      review: {
        id: 1,
        course_id: 2,
        learner_id: null,
        viewer_owned: true,
        author_name: "匿名学员",
        rating: 5,
        body: "Useful course.",
        visibility: "public",
        hidden_reason: null,
        hidden_by_staff_id: null,
        hidden_at: null,
        edited: false,
        created_at: "2026-08-25 10:00:00",
        updated_at: "2026-08-25 10:00:00",
      },
      replies: [],
    });

    expect(parsed.review.learner_id).toBeNull();
    expect(parsed.review.viewer_owned).toBe(true);
    expect(parsed.review.author_name).toBe("匿名学员");
  });

  it("accepts moderation metadata and edited state for replies", () => {
    const parsed = ReviewReplyDTO.parse({
      id: 3,
      review_id: 1,
      parent_id: null,
      kind: "admin",
      author_learner_id: null,
      author_staff_id: 4,
      viewer_owned: false,
      author_name: "课程运营",
      body: "Thanks for the feedback.",
      visibility: "hidden",
      hidden_reason: "Contains personal data.",
      hidden_by_staff_id: 4,
      hidden_at: "2026-08-25 10:01:00",
      edited: true,
      created_at: "2026-08-25 10:00:00",
      updated_at: "2026-08-25 10:00:01",
    });

    expect(parsed.visibility).toBe("hidden");
    expect(parsed.hidden_by_staff_id).toBe(4);
    expect(parsed.viewer_owned).toBe(false);
    expect(parsed.edited).toBe(true);
  });

  it("keeps the viewer review available outside the current page", () => {
    const parsed = ReviewListDTO.parse({
      items: [],
      viewer_review: {
        id: 8,
        course_id: 2,
        learner_id: null,
        viewer_owned: true,
        author_name: "匿名学员",
        rating: 4,
        body: "较早发表的评价",
        visibility: "public",
        hidden_reason: null,
        hidden_by_staff_id: null,
        hidden_at: null,
        edited: false,
        created_at: "2026-08-20 10:00:00",
        updated_at: "2026-08-20 10:00:00",
      },
      total: 21,
      page: 1,
      limit: 10,
    });

    expect(parsed.items).toEqual([]);
    expect(parsed.viewer_review?.id).toBe(8);
    expect(parsed.viewer_review?.viewer_owned).toBe(true);
  });

  it("validates scoped moderation course options", () => {
    const schema = Reflect.get(reviewContracts, "ReviewFilterOptionsDTO") as
      { parse(value: unknown): unknown } | undefined;

    expect(schema).toBeDefined();
    expect(
      schema?.parse({ courses: [{ id: 12, title: "TypeScript 深入实践" }] }),
    ).toEqual({
      courses: [{ id: 12, title: "TypeScript 深入实践" }],
    });
  });
});
