import { describe, expect, it } from "vitest";
import {
  ContentTodoApproveRequestSchema,
  ContentTodoCloseRequestSchema,
  ContentTodoDetailSchema,
  ContentTodoErrorCodes,
  ContentTodoLabelSchema,
  ContentTodoListResponseSchema,
  ContentTodoRejectRequestSchema,
} from "../contentTodo.js";

const candidate = {
  id: 3,
  version: 1,
  target_course_id: 12,
  target_chapter_id: 4,
  target_lesson_id: 8,
  target_kind: "lesson_markdown" as const,
  body: "补充一个例子。",
  body_format: "markdown" as const,
  base_content_fingerprint: "a".repeat(64),
  generator: "local",
  status: "draft" as const,
  generated_by_staff_id: 9,
  generated_at: "2026-09-10T16:00:00+08:00",
  approved_by_staff_id: null,
  approved_at: null,
  rejection_reason: null,
};

const todo = {
  id: 21,
  source_type: "question_pending" as const,
  source_key: "77",
  source_course_id: 12,
  workflow_status: "awaiting_approval" as const,
  label: "missing_example" as const,
  target: { course_id: 12, chapter_id: 4, lesson_id: 8 },
  first_response_at: null,
  first_response_kind: null,
  first_response_confirmed: false,
  result_type: null,
  close_reason_code: null,
  close_reason_note: null,
  resolved_at: null,
  resolved_by_staff_id: null,
  version: 2,
  title: "为什么没有例子？",
  course_title: "内容待办课程",
  age_seconds: 3600,
  age_label: "1 小时",
  created_at: "2026-09-10T15:00:00+08:00",
  updated_at: "2026-09-10T16:00:00+08:00",
};

describe("content todo contracts", () => {
  it("locks labels and reject/close payloads", () => {
    expect(ContentTodoLabelSchema.safeParse("missing_example").success).toBe(true);
    expect(ContentTodoLabelSchema.safeParse("typo").success).toBe(false);
    expect(ContentTodoRejectRequestSchema.safeParse({ reason: "草稿不可用" }).success).toBe(true);
    expect(ContentTodoRejectRequestSchema.safeParse({ reason: "" }).success).toBe(false);
    expect(
      ContentTodoCloseRequestSchema.safeParse({
        close_reason_code: "already_covered",
        close_reason_note: "已有同类说明",
      }).success,
    ).toBe(true);
    expect(
      ContentTodoCloseRequestSchema.safeParse({
        close_reason_code: "skipped",
        close_reason_note: "x",
      }).success,
    ).toBe(false);
    expect(ContentTodoApproveRequestSchema.safeParse({ notify_mode: "enrolled" }).success).toBe(
      true,
    );
    expect(ContentTodoApproveRequestSchema.safeParse({ notify_mode: "everyone" }).success).toBe(
      false,
    );
  });

  it("parses list and detail payloads", () => {
    const list = ContentTodoListResponseSchema.parse({
      items: [todo],
      total: 1,
      page: 1,
      limit: 20,
    });
    expect(list.items[0]!.id).toBe(21);

    const detail = ContentTodoDetailSchema.parse({
      ...todo,
      source: {
        source_type: "question_pending",
        source_key: "77",
        course_id: 12,
        course_title: "内容待办课程",
        chapter_id: 4,
        lesson_id: 8,
        learner_id: 101,
        title: "为什么没有例子？",
        body: "请补充一个例子。",
        created_at: "2026-09-10T15:00:00+08:00",
      },
      candidates: [candidate],
      audit: [
        {
          id: 1,
          action: "content_todo.candidate_generated",
          actor_id: 9,
          payload: { candidate_id: 3 },
          created_at: "2026-09-10T16:00:00+08:00",
        },
      ],
    });
    expect(detail.candidates[0]!.status).toBe("draft");
  });

  it("rejects unknown enums and short fingerprints", () => {
    expect(
      ContentTodoDetailSchema.safeParse({
        ...todo,
        source: {
          source_type: "review_pending",
          source_key: "77",
          course_id: 12,
          course_title: "内容待办课程",
          chapter_id: 4,
          lesson_id: 8,
          learner_id: 101,
          title: "评价",
          body: "x",
          created_at: "2026-09-10T15:00:00+08:00",
        },
        candidates: [],
        audit: [],
      }).success,
    ).toBe(false);
    expect(ContentTodoErrorCodes.CONTENT_TODO_VERSION_CONFLICT).toBe(
      "CONTENT_TODO_VERSION_CONFLICT",
    );
  });
});
