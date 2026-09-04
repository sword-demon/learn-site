import { z } from 'zod';

export const PaymentChannel = z.enum(['wxpay', 'alipay']);
export type PaymentChannel = z.infer<typeof PaymentChannel>;

const PaymentApiUrl = z
  .string()
  .url()
  .endsWith('/')
  .refine((value) => value.startsWith('https://'), 'HTTPS_REQUIRED');

export const PaymentConfig = z.object({
  enabled: z.boolean(),
  api_url: PaymentApiUrl,
  pid: z.string().min(8).max(64),
  merchant_key_masked: z.string().regex(/^\*{8,}\w{0,4}$|^$/),
  notify_url: z.string().url(),
  return_url: z.string().url(),
  enabled_channels: z.array(PaymentChannel).min(1),
  whitelist_only: z.boolean(),
  version: z.number().int().positive(),
  updated_at: z.string().datetime().nullable(),
});
export type PaymentConfig = z.infer<typeof PaymentConfig>;

export const PaymentConfigUpdateInput = z.object({
  enabled: z.boolean(),
  api_url: PaymentApiUrl,
  pid: z.string().min(8).max(64),
  merchant_key: z.string().max(64),
  notify_url: z.string().url(),
  return_url: z.string().url(),
  enabled_channels: z.array(PaymentChannel).min(1),
  whitelist_only: z.boolean(),
  version: z.number().int().positive().optional(),
});
export type PaymentConfigUpdateInput = z.infer<typeof PaymentConfigUpdateInput>;
