import { beforeEach, describe, expect, it, vi } from 'vitest'

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}))

vi.mock('@/api/http', () => ({ default: mockHttp }))

import { listMaps, uploadMapCover } from '@/api/learningMaps'

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

  it('uploads a map cover through the map-scoped endpoint', async () => {
    const file = new File(['image'], 'map-cover.webp', { type: 'image/webp' })
    mockHttp.post.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          key: 'covers/2026/08/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp',
          url: '/api/media/covers/2026/08/bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb.webp',
          mime_type: 'image/webp',
          size_bytes: 5,
        },
        error: null,
      },
    })

    await expect(uploadMapCover({ file })).resolves.toMatchObject({
      mime_type: 'image/webp',
      size_bytes: 5,
    })
    const call = mockHttp.post.mock.calls[0]
    expect(call).toBeDefined()
    expect(call![0]).toBe('/map-covers')
    expect(call![1].get('file')).toBe(file)
  })
})
