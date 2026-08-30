import { describe, expect, it } from 'vitest'
import {
  AdminBannerDTO,
  AdminBannerListDTO,
  BannerPublicDTO,
  CreateBannerInput,
  HomePayload,
  UpdateBannerInput,
} from '../index.js'

const image = {
  image_url: '/api/media/banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  image_key: 'banners/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
}

describe('banner contracts', () => {
  it('parses public and admin banner shapes without leaking admin fields publicly', () => {
    const admin = AdminBannerDTO.parse({
      id: 1,
      ...image,
      link_url: '/courses/1',
      sort_order: 0,
      is_enabled: true,
      created_at: '2026-08-30T10:00:00+08:00',
      updated_at: '2026-08-30T10:00:00+08:00',
    })
    expect(admin.is_enabled).toBe(true)

    const publicBanner = BannerPublicDTO.parse({
      id: admin.id,
      image_url: admin.image_url,
      link_url: admin.link_url,
      sort_order: admin.sort_order,
      is_enabled: true,
    })
    expect(publicBanner).not.toHaveProperty('is_enabled')
    expect(publicBanner).not.toHaveProperty('image_key')
  })

  it('uses defaults for creation and a missing home banner list', () => {
    const input = CreateBannerInput.parse(image)
    expect(input.sort_order).toBe(0)
    expect(input.is_enabled).toBe(true)

    const home = HomePayload.parse({
      categories: [],
      site_intro: {
        title: '学习平台',
        subtitle: '',
        body_html: '',
        contact_email: '',
        updated_at: null,
      },
      recent_courses: [],
    })
    expect(home.banners).toEqual([])
  })

  it('requires at least one update field and parses the admin list envelope', () => {
    expect(UpdateBannerInput.safeParse({}).success).toBe(false)
    const list = AdminBannerListDTO.parse({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
    })
    expect(list.items).toEqual([])
  })
})
