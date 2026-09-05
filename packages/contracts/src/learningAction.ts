import { z } from "zod";

const IsoDateTime = z.string().min(1);

export const NextActionTargetDTO = z.object({
  resource_type: z.enum([
    "lesson",
    "course",
    "learning_map",
    "order",
    "coupon",
    "course_list",
    "map_list",
    "coupon_list",
    "order_list",
    "message",
  ]),
  resource_id: z.number().int().positive().nullable(),
  path: z.string().min(1),
});
export type NextActionTargetDTO = z.infer<typeof NextActionTargetDTO>;

export const NextActionDTO = z.object({
  type: z.enum([
    "pay_order",
    "use_coupon",
    "continue_lesson",
    "open_message",
    "continue_map",
    "start_favorite_course",
    "browse_courses",
    "browse_maps",
  ]),
  priority: z.number().int().min(1).max(7),
  rule_code: z.string().min(1),
  reason_code: z.string().min(1),
  title: z.string().min(1),
  reason: z.string().min(1),
  target: NextActionTargetDTO,
  availability: z.enum(["available", "requires_access", "unavailable"]),
  availability_reason: z.string().nullable(),
  generated_at: IsoDateTime,
});
export type NextActionDTO = z.infer<typeof NextActionDTO>;

export const NextActionFallbackDTO = NextActionDTO;
export type NextActionFallbackDTO = z.infer<typeof NextActionFallbackDTO>;

export const LearnerNextActionDTO = z.object({
  state: z.enum(["ready", "empty", "degraded"]),
  action: NextActionDTO.nullable(),
  fallback: NextActionFallbackDTO.nullable(),
  evaluated_at: IsoDateTime,
  degraded_dependencies: z.array(z.string()),
});
export type LearnerNextActionDTO = z.infer<typeof LearnerNextActionDTO>;
