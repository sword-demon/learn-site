import { describe, expect, it } from 'vitest'
import { ShareLinkDTO, SharePosterDTO } from '../favorite.js'

describe('share contracts', () => {
  it('parses a stable course link independently of a poster', () => {
    expect(ShareLinkDTO.parse({ course_id: 9, share_url: '/courses/9' })).toEqual({
      course_id: 9,
      share_url: '/courses/9',
    })
  })

  it('parses the immutable poster snapshot and failure status', () => {
    const parsed = SharePosterDTO.parse({
      poster_id: 4,
      token: 'poster-token',
      share_url: '/courses/9',
      render_status: 'failed',
      snapshot: {
        cover_url: null,
        title: 'Vue 组件设计',
        teacher_name: '林老师',
        price_label: '免费',
      },
    })

    expect(parsed.render_status).toBe('failed')
    expect(parsed.snapshot.price_label).toBe('免费')
  })
})
