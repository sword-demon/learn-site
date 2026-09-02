import { z } from 'zod';

// Canonical JSON envelope per contracts/conventions.md.
// Error codes are stable strings; see ApiResponse::code() in the API.
export const ApiOk = <T extends z.ZodTypeAny>(data: T) =>
  z.object({
    ok: z.literal(true),
    data,
    error: z.null().optional(),
    meta: z
      .object({
        request_id: z.string(),
      })
      .optional(),
  });

export const ApiErr = z.object({
  ok: z.literal(false),
  data: z.null(),
  error: z
    .object({
      code: z.string(),
      message: z.string(),
      course_id: z.number().int().positive().optional(),
      course_title: z.string().optional(),
    })
    .passthrough(),
  meta: z
    .object({
      request_id: z.string(),
    })
    .optional(),
});

export const ApiResponse = <T extends z.ZodTypeAny>(data: T) =>
  z.union([ApiOk(data), ApiErr]);
export type ApiOk<T> = { ok: true; data: T; error?: null; meta?: { request_id: string } };
export type ApiErr = z.infer<typeof ApiErr>;
export type ApiResponse<T> = ApiOk<T> | ApiErr;

export const ErrorCode = z.enum([
  'CAPTCHA_INVALID',
  'TOKEN_EXPIRED',
  'TOKEN_REVOKED',
  'UNAUTHENTICATED',
  'FORBIDDEN',
  'NOT_FOUND',
  'VALIDATION_FAILED',
  'LOGIN_INVALID',
  'RATE_LIMITED',
  'CONFLICT',
  'LAST_SUPER_ADMIN',
  'CATEGORY_IN_USE',
  'PAYMENT_UNSETTLED',
  'ACCOUNT_DISABLED',
  'ALREADY_CHECKED_IN',
  'INTERNAL',
]);
export type ErrorCode = z.infer<typeof ErrorCode>;
