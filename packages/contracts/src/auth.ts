import { z } from 'zod';

export const PhoneSchema = z
  .string()
  .regex(/^1[3-9]\d{9}$/, 'INVALID_PHONE');

export const PasswordSchema = z
  .string()
  .min(8, 'PASSWORD_TOO_SHORT')
  .max(72, 'PASSWORD_TOO_LONG');

export const LearnerLoginInput = z.object({
  phone: PhoneSchema,
  password: PasswordSchema,
  captcha_id: z.string().min(1),
  captcha_answer: z.string().min(1),
});
export type LearnerLoginInput = z.infer<typeof LearnerLoginInput>;

export const LearnerRegisterInput = LearnerLoginInput.extend({
  // Registration reuses captcha+phone+password; extra fields can be added later.
});
export type LearnerRegisterInput = z.infer<typeof LearnerRegisterInput>;

export const AdminLoginInput = z.object({
  account: z
    .string()
    .min(3)
    .max(64)
    // Reject 11-digit phone-shaped account strings; matches data-model rule.
    .refine((v) => !/^1[3-9]\d{9}$/.test(v), 'INVALID_LOGIN'),
  password: PasswordSchema,
  captcha_id: z.string().min(1),
  captcha_answer: z.string().min(1),
});
export type AdminLoginInput = z.infer<typeof AdminLoginInput>;

export const RefreshInput = z.object({
  refresh_token: z.string().min(1),
});
export type RefreshInput = z.infer<typeof RefreshInput>;

export const TokenPair = z.object({
  access_token: z.string(),
  access_expires_in: z.number().int().positive(),
  refresh_token: z.string(),
  refresh_expires_in: z.number().int().positive(),
});
export type TokenPair = z.infer<typeof TokenPair>;