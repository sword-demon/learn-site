import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
}))

vi.mock('@/api/http', () => ({ default: mockHttp }))

import { listMaps } from '@/api/learningMaps'

describe('learning maps API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('unwraps the standard API envelope before returning the paginated list', async () => {
    const payload = { items: [], total: 0, page: 1, limit: 50 }
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: payload,
        error: null,
      },
    })

    await expect(listMaps()).resolves.toEqual(payload)
  })
})
