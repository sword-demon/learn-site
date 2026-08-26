import { z } from 'zod'
import { PasswordSchema } from './auth.js'

/**
 * First-login password change. Both current and new are required; the new
 * must satisfy PasswordSchema (8–72 chars).
 */
export const FirstPasswordInput = z.object({
  current_password: z.string().min(1).max(72),
  new_password: PasswordSchema,
})
export type FirstPasswordInput = z.infer<typeof FirstPasswordInput>

export const FirstPasswordOutput = z.object({
  changed: z.literal(true),
})
export type FirstPasswordOutput = z.infer<typeof FirstPasswordOutput>