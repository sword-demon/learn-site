import { describe, expect, it } from 'vitest'
import { resolveAdminNavigation, type AdminNavigationTarget } from '@/router/access'

function target(overrides: Partial<AdminNavigationTarget> = {}): AdminNavigationTarget {
  return {
    name: 'course-edit',
    path: '/courses/42/edit',
    fullPath: '/courses/42/edit',
    public: false,
    permission: 'course.manage',
    ...overrides,
  }
}

describe('resolveAdminNavigation', () => {
  it('rejects a direct protected URL without exposing the destination page', () => {
    expect(resolveAdminNavigation(target(), {
      hasTokens: true,
      mustChangePassword: false,
      permissionCodes: ['qa.view', 'qa.answer'],
      fallbackPath: '/qa',
    })).toEqual({
      path: '/forbidden',
      query: { from: '/courses/42/edit' },
    })
  })

  it('allows a Q&A route for a Q&A-only account', () => {
    expect(resolveAdminNavigation(target({
      name: 'qa',
      path: '/qa',
      fullPath: '/qa',
      permission: 'qa.view',
    }), {
      hasTokens: true,
      mustChangePassword: false,
      permissionCodes: ['qa.view', 'qa.answer'],
      fallbackPath: '/qa',
    })).toBe(true)
  })

  it('sends a Q&A-only account from the default dashboard to its first accessible module', () => {
    expect(resolveAdminNavigation(target({
      name: 'dashboard',
      path: '/',
      fullPath: '/',
      permission: 'dashboard.view',
    }), {
      hasTokens: true,
      mustChangePassword: false,
      permissionCodes: ['qa.view', 'qa.answer'],
      fallbackPath: '/qa',
    })).toEqual({ path: '/qa' })
  })

  it('lets a super admin access every protected route', () => {
    expect(resolveAdminNavigation(target(), {
      hasTokens: true,
      mustChangePassword: false,
      permissionCodes: ['*'],
      fallbackPath: '/',
    })).toBe(true)
  })

  it('sends a must-change account to the dedicated first-password route', () => {
    expect(resolveAdminNavigation(target(), {
      hasTokens: true,
      mustChangePassword: true,
      permissionCodes: ['*'],
      fallbackPath: '/',
    })).toEqual({
      path: '/first-password',
      query: { next: '/courses/42/edit' },
    })
  })

  it('allows a must-change account to open the dedicated first-password route', () => {
    expect(resolveAdminNavigation({
      name: 'first-password',
      path: '/first-password',
      fullPath: '/first-password?next=/courses/42/edit',
      public: false,
    }, {
      hasTokens: true,
      mustChangePassword: true,
      permissionCodes: ['*'],
      fallbackPath: '/',
    })).toBe(true)
  })
})
