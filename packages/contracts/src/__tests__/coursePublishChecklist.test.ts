import { describe, expect, it } from "vitest";
import {
  ChecklistFindingDTO,
  ImpactSummaryDTO,
  PublishChecklistDTO,
  PublishCourseInput,
} from "../coursePublishChecklist.js";

const impact = {
  maps: { published_count: 0, draft_count: 0, items: [] },
  entitlements: { active_count: 2 },
  progress: {
    enrollment_count: 2,
    current_denominator: 1,
    next_denominator: 1,
    will_recalculate: false,
    completed_preserved: true,
  },
  notification: {
    will_dispatch: true,
    recipient_count: 3,
    recipient_unavailable: false,
  },
};

describe("publish checklist contract", () => {
  it("parses a complete checklist", () => {
    expect(
      PublishChecklistDTO.safeParse({
        course_id: 1,
        course_title: "Course",
        course_status: "draft",
        generated_at: "2026-09-06T12:00:00+08:00",
        content_fingerprint: "abc",
        hard_error_count: 0,
        warning_count: 0,
        can_publish: true,
        catalog: {
          category_id: 1,
          category_name: "Category",
          category_enabled: true,
          intro_present: true,
          price_mode: "free",
          price_valid: true,
          effective_chapter_count: 1,
          effective_lesson_count: 1,
          trial_lesson_count: 0,
          chapters: [],
        },
        findings: [],
        impact,
      }).success,
    ).toBe(true);
  });
  it("rejects unknown finding codes", () => {
    expect(
      ChecklistFindingDTO.safeParse({
        code: "UNKNOWN",
        severity: "hard",
        message: "Error",
        scope: "course",
        chapter_id: null,
        lesson_id: null,
      }).success,
    ).toBe(false);
  });
  it("defaults warning acknowledgment and rejects coerced booleans", () => {
    expect(PublishCourseInput.parse({})).toEqual({
      acknowledge_warnings: false,
    });
    expect(
      PublishCourseInput.safeParse({ acknowledge_warnings: "true" }).success,
    ).toBe(false);
  });
  it("requires completed progress preservation and accepts unavailable audience", () => {
    expect(
      ImpactSummaryDTO.safeParse({
        ...impact,
        progress: { ...impact.progress, completed_preserved: false },
      }).success,
    ).toBe(false);
    expect(
      ImpactSummaryDTO.parse({
        ...impact,
        notification: {
          will_dispatch: true,
          recipient_count: null,
          recipient_unavailable: true,
        },
      }).notification.recipient_count,
    ).toBeNull();
  });
});
