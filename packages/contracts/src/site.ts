import { z } from 'zod'

export const SiteIntro = z.object({
  title: z.string().min(1).max(80),
  subtitle: z.string().max(160),
  body_html: z.string().max(4000),
  contact_email: z.string().max(120),
  updated_at: z.string().nullable(),
})
export type SiteIntro = z.infer<typeof SiteIntro>

export const SiteProfileUpdateInput = z.object({
  title: z.string().min(1).max(80),
  subtitle: z.string().max(160),
  body_html: z.string().max(4000),
  contact_email: z
    .string()
    .max(120)
    .regex(/^$|^[^@\s]+@[^@\s]+\.[^@\s]+$/, 'INVALID_EMAIL'),
})
export type SiteProfileUpdateInput = z.infer<typeof SiteProfileUpdateInput>

export const SiteProfileUpdatedDTO = z.object({
  ok: z.literal(true),
  truncated: z.boolean(),
})
export type SiteProfileUpdatedDTO = z.infer<typeof SiteProfileUpdatedDTO>
