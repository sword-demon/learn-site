import { z } from 'zod';

export const CaptchaChallenge = z.object({
  captcha_id: z.string().min(1),
  // base64 PNG; size cap enforced server-side.
  image: z.string().min(1).max(200_000),
  ttl_seconds: z.literal(120),
});
export type CaptchaChallenge = z.infer<typeof CaptchaChallenge>;

export const CaptchaAnswer = z.object({
  captcha_id: z.string().min(1),
  captcha_answer: z.string().min(1).max(8),
});
export type CaptchaAnswer = z.infer<typeof CaptchaAnswer>;