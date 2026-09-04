import { z } from 'zod';

export const PaymentWhitelistEntry = z.object({
  id: z.number().int().positive(),
  phone_masked: z.string().regex(/^1\d{2}\*{4}\d{4}$|^1\d{10}$/),
  phone: z.string().regex(/^1\d{10}$/).optional(),
  enabled: z.boolean(),
  note: z.string().max(120).nullable(),
  created_at: z.string().datetime(),
  updated_at: z.string().datetime(),
});
export type PaymentWhitelistEntry = z.infer<typeof PaymentWhitelistEntry>;

export const PaymentWhitelistCreateInput = z.object({
  phone: z.string().regex(/^1\d{10}$/, 'INVALID_PHONE'),
  enabled: z.boolean().default(true),
  note: z.string().max(120).optional(),
});
export type PaymentWhitelistCreateInput = z.infer<typeof PaymentWhitelistCreateInput>;

export const PaymentWhitelistUpdateInput = z.object({
  enabled: z.boolean(),
  note: z.string().max(120).nullable(),
});
export type PaymentWhitelistUpdateInput = z.infer<typeof PaymentWhitelistUpdateInput>;

export const PaymentWhitelistListResponse = z.object({
  items: z.array(PaymentWhitelistEntry),
  total: z.number().int().nonnegative(),
  page: z.number().int().positive(),
  limit: z.number().int().positive(),
});
export type PaymentWhitelistListResponse = z.infer<typeof PaymentWhitelistListResponse>;
