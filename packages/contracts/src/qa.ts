import { z } from "zod";

// ─── shared Q&A shapes (Phase 11 / US4) ──────────────────────────────

export const QuestionStatus = z.enum(["pending", "answered", "closed"]);
export type QuestionStatus = z.infer<typeof QuestionStatus>;

export const QuestionSummaryDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  chapter_id: z.number().int().positive().nullable(),
  lesson_id: z.number().int().positive().nullable(),
  learner_id: z.number().int().positive(),
  title: z.string(),
  status: QuestionStatus,
  answered_at: z.string(),
  created_at: z.string(),
  updated_at: z.string(),
});
export type QuestionSummaryDTO = z.infer<typeof QuestionSummaryDTO>;

export const QuestionMessageDTO = z.object({
  id: z.number().int().positive(),
  kind: z.enum(["questioner", "admin", "system"]),
  author_learner_id: z.number().int().positive().nullable(),
  author_staff_id: z.number().int().positive().nullable(),
  body: z.string(),
  created_at: z.string(),
});
export type QuestionMessageDTO = z.infer<typeof QuestionMessageDTO>;

export const QuestionThreadDTO = z.object({
  question: QuestionSummaryDTO,
  messages: z.array(QuestionMessageDTO),
});
export type QuestionThreadDTO = z.infer<typeof QuestionThreadDTO>;

export const QuestionListDTO = z.object({
  items: z.array(QuestionSummaryDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type QuestionListDTO = z.infer<typeof QuestionListDTO>;

// admin inbox adds status echo
export const AdminInboxDTO = QuestionListDTO.extend({
  status: QuestionStatus,
});
export type AdminInboxDTO = z.infer<typeof AdminInboxDTO>;

export const QuestionFilterOptionDTO = z.object({
  id: z.number().int().positive(),
  title: z.string(),
});
export type QuestionFilterOptionDTO = z.infer<typeof QuestionFilterOptionDTO>;

export const QuestionFilterOptionsDTO = z.object({
  courses: z.array(QuestionFilterOptionDTO),
  lessons: z.array(QuestionFilterOptionDTO),
});
export type QuestionFilterOptionsDTO = z.infer<typeof QuestionFilterOptionsDTO>;

export const AskQuestionInput = z.object({
  title: z.string().min(1).max(128),
  body: z.string().min(1).max(4000),
});
export type AskQuestionInput = z.infer<typeof AskQuestionInput>;

export const FollowupInput = z.object({
  body: z.string().min(1).max(4000),
});
export type FollowupInput = z.infer<typeof FollowupInput>;

export const AnswerInput = z.object({
  body: z.string().min(1).max(4000),
});
export type AnswerInput = z.infer<typeof AnswerInput>;
