import { z } from 'zod'
import { TokenPair } from './auth.js'
import { ApiResponse } from './envelope.js'

// ponytail: hoisted to module scope so login / refresh don't rebuild
// the Zod schema on every call. Zod caches internally but extend +
// ApiResponse composition was the visible per-call cost.
const TokenPairWithFlag = TokenPair.extend({
  must_change_password: z.boolean().optional(),
  permission_codes: z.array(z.string()).optional(),
})
const TokenEnvelopeSchema = ApiResponse(TokenPairWithFlag)

export type TokenEnvelopeData = TokenPair & {
  must_change_password?: boolean
  permission_codes?: string[]
}

/**
 * Parse an envelope response whose `data` is a TokenPair. The actual API
 * (e.g. /auth/login) returns
 *   `{ data: { ...TokenPair, must_change_password, permission_codes } }`
 * where `must_change_password` is a boolean and `permission_codes` is a
 * string[] (or `['*']` for super admin). The client uses
 * `permission_codes` to filter the sidebar (US13 / T059) and the route
 * guard (T060). The parser keeps both fields exposed so callers can
 * branch on first-login state and permission gating.
 */
export function parseTokenEnvelope(data: unknown): TokenEnvelopeData | null {
  const parsed = TokenEnvelopeSchema.safeParse(data)
  if (!parsed.success || !parsed.data.ok) {
    return null
  }
  return parsed.data.data as TokenEnvelopeData
}
