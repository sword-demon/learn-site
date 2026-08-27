// @vitest-environment happy-dom

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'

import { AUTH_STORAGE_KEY } from '@/api/http'

const pair = {
  access_token: 'access-token',
  access_expires_in: 900,
  refresh_token: 'refresh-token',
  refresh_expires_in: 604800,
  must_change_password: false,
  permission_codes: ['dashboard.view', 'course.view'],
}

describe('admin auth session persistence', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.resetModules()
  })

  afterEach(() => {
    localStorage.clear()
  })

  it('writes tokens so a full page reload can restore the session', async () => {
    const { setTokens } = await import('@/api/http')
    setTokens(pair)

    const stored = localStorage.getItem(AUTH_STORAGE_KEY)
    expect(stored).not.toBeNull()
    expect(JSON.parse(stored ?? '')).toMatchObject({
      access: pair.access_token,
      refresh: pair.refresh_token,
      mustChangePassword: false,
      permissionCodes: pair.permission_codes,
    })
  })

  it('hydrates in-memory auth from localStorage on a fresh module load', async () => {
    localStorage.setItem(
      AUTH_STORAGE_KEY,
      JSON.stringify({
        access: pair.access_token,
        refresh: pair.refresh_token,
        mustChangePassword: false,
        permissionCodes: pair.permission_codes,
      }),
    )

    const { hasTokens, permissionCodes, mustChangePassword } = await import('@/api/http')
    expect(hasTokens()).toBe(true)
    expect(mustChangePassword()).toBe(false)
    expect(permissionCodes()).toEqual(pair.permission_codes)
  })

  it('clears persisted tokens on logout', async () => {
    const { setTokens, clearTokens, hasTokens } = await import('@/api/http')
    setTokens(pair)
    clearTokens()

    expect(hasTokens()).toBe(false)
    expect(localStorage.getItem(AUTH_STORAGE_KEY)).toBeNull()
  })
})
