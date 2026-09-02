import { z } from 'zod'

export const ActivationCodeStatus = z.enum(['unused', 'redeemed', 'void', 'expired'])
export type ActivationCodeStatus = z.infer<typeof ActivationCodeStatus>

export const CreateActivationCodeBatchInput = z.object({
  quantity: z.number().int().min(1).max(1000),
  expires_at: z.string().nullable(),
})
export type CreateActivationCodeBatchInput = z.infer<typeof CreateActivationCodeBatchInput>

export const ActivationCodeBatchCreatedDTO = z.object({
  id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  quantity: z.number().int().min(1).max(1000),
  expires_at: z.string().nullable(),
  created_at: z.string(),
  codes: z.array(z.string()).min(1),
})
export type ActivationCodeBatchCreatedDTO = z.infer<typeof ActivationCodeBatchCreatedDTO>

export const ActivationCodeRedeemerDTO = z.object({
  account_id: z.number().int().positive(),
  nickname: z.string(),
})
export type ActivationCodeRedeemerDTO = z.infer<typeof ActivationCodeRedeemerDTO>

export const AdminActivationCodeItemDTO = z.object({
  id: z.number().int().positive(),
  batch_id: z.number().int().positive(),
  course_id: z.number().int().positive(),
  display_code: z.string().regex(/^[0-9A-Z]{4}\*{4}[0-9A-Z]{4}$/),
  status: ActivationCodeStatus,
  expires_at: z.string().nullable(),
  redeemed_by: ActivationCodeRedeemerDTO.nullable(),
  redeemed_at: z.string().nullable(),
  voided_at: z.string().nullable(),
  created_at: z.string(),
})
export type AdminActivationCodeItemDTO = z.infer<typeof AdminActivationCodeItemDTO>

export const AdminActivationCodeListDTO = z.object({
  items: z.array(AdminActivationCodeItemDTO),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
})
export type AdminActivationCodeListDTO = z.infer<typeof AdminActivationCodeListDTO>

export const VoidActivationCodeResultDTO = z.object({ voided: z.literal(true) })
export type VoidActivationCodeResultDTO = z.infer<typeof VoidActivationCodeResultDTO>

export const RedeemActivationCodeInput = z.object({
  code: z.string().trim().min(1).max(32),
})
export type RedeemActivationCodeInput = z.infer<typeof RedeemActivationCodeInput>

export const RedeemActivationCodeResultDTO = z.object({
  granted: z.literal(true),
  course_id: z.number().int().positive(),
  course_title: z.string(),
  source: z.literal('activation_code'),
})
export type RedeemActivationCodeResultDTO = z.infer<typeof RedeemActivationCodeResultDTO>

export const ActivationCodeError = z.enum([
  'ACTIVATION_CODE_INVALID',
  'ACTIVATION_CODE_REDEEMED',
  'ACTIVATION_CODE_VOID',
  'ACTIVATION_CODE_EXPIRED',
  'ACTIVATION_CODE_COURSE_UNAVAILABLE',
  'ENTITLEMENT_ALREADY_ACTIVE',
  'RATE_LIMITED',
])
export type ActivationCodeError = z.infer<typeof ActivationCodeError>
