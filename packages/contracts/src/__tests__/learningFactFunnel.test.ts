import { describe, expect, it } from "vitest";
import {
  LearningFactFunnelDisclaimerSchema,
  LearningFactFunnelDTO,
  LearningFactFunnelQuerySchema,
} from "../learningFactFunnel.js";

const sample = {
  course_id: 12,
  window_days: 30 as const,
  source: "all" as const,
  generated_at: "2026-09-11T12:00:00+08:00",
  disclaimer:
    "本报告展示事实转化，不表示增量效果，也不把订单成功或券已使用当成学习完成。",
  no_effective_lesson: false,
  stages: [
    {
      id: "entitled" as const,
      label: "访问权生效",
      count: 10,
      of_cohort_rate: 1,
      of_previous_rate: 1,
    },
    {
      id: "first_opened" as const,
      label: "首次打开课节",
      count: 6,
      of_cohort_rate: 0.6,
      of_previous_rate: 0.6,
    },
    {
      id: "valid_progress" as const,
      label: "产生有效进度",
      count: 4,
      of_cohort_rate: 0.4,
      of_previous_rate: 0.6667,
    },
    {
      id: "completed" as const,
      label: "完成课程",
      count: 2,
      of_cohort_rate: 0.2,
      of_previous_rate: 0.5,
    },
  ] as [
    {
      id: "entitled";
      label: string;
      count: number;
      of_cohort_rate: number | null;
      of_previous_rate: number | null;
    },
    {
      id: "first_opened";
      label: string;
      count: number;
      of_cohort_rate: number | null;
      of_previous_rate: number | null;
    },
    {
      id: "valid_progress";
      label: string;
      count: number;
      of_cohort_rate: number | null;
      of_previous_rate: number | null;
    },
    {
      id: "completed";
      label: string;
      count: number;
      of_cohort_rate: number | null;
      of_previous_rate: number | null;
    },
  ],
  pending: {
    in_window: 3,
    in_window_label: "窗口进行中、尚未开始" as const,
    window_elapsed: 1,
    window_elapsed_label: "窗口内未转化" as const,
  },
  trial: {
    learners: 2,
    note: "未取得课程访问权时打开试看课节的登录学员，不含访客曝光" as const,
  },
  orders: {
    succeeded: 5,
    buckets: [{ status: "succeeded", count: 5 }],
    note: "订单成功不是完成课程" as const,
  },
  publish_reach: {
    dispatch_count: 1,
    recipient_count: 8,
    entitled_count: 10,
    note: "发布触达与已有访问权人数分开，触达不是首次打开课节" as const,
  },
};

describe("learningFactFunnel", () => {
  it("defaults query to 30 days and all sources", () => {
    expect(LearningFactFunnelQuerySchema.parse({})).toEqual({
      window_days: 30,
      source: "all",
    });
  });

  it("rejects illegal window and source", () => {
    expect(() =>
      LearningFactFunnelQuerySchema.parse({ window_days: 14 }),
    ).toThrow();
    expect(() =>
      LearningFactFunnelQuerySchema.parse({ source: "coupon" }),
    ).toThrow();
  });

  it("locks disclaimer and pending labels", () => {
    expect(LearningFactFunnelDisclaimerSchema.parse(sample.disclaimer)).toBe(
      sample.disclaimer,
    );
    const parsed = LearningFactFunnelDTO.parse(sample);
    expect(parsed.pending.in_window_label).toBe("窗口进行中、尚未开始");
    expect(parsed.pending.window_elapsed_label).toBe("窗口内未转化");
    expect(parsed.pending.in_window_label).not.toContain("流失");
    expect(parsed.pending.window_elapsed_label).not.toContain("弃学");
  });
});
