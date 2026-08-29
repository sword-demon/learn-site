// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const learnerApi = vi.hoisted(() => ({
  fetchFavorites: vi.fn(),
  removeFavorite: vi.fn(),
}))

vi.mock('@/api/learner', () => learnerApi)

import FavoritesView from '@/views/me/FavoritesView.vue'

describe('FavoritesView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    learnerApi.fetchFavorites.mockResolvedValue({
      items: [{
        course_id: 9,
        title: '已下架课程',
        cover_url: null,
        teacher_name: '林老师',
        price_mode: 'free',
        list_price: 0,
        status: 'unpublished',
        favorited_at: '2026-08-28 10:00:00',
      }],
      total: 1,
      page: 1,
      limit: 50,
    })
    learnerApi.removeFavorite.mockResolvedValue({ course_id: 9, favorited: false })
  })

  it('keeps unavailable favorites identifiable and removes them locally', async () => {
    const wrapper = mount(FavoritesView, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('已下架课程')
    expect(wrapper.text()).toContain('暂不可用')
    await wrapper.get('button').trigger('click')
    await flushPromises()

    expect(learnerApi.removeFavorite).toHaveBeenCalledWith(9)
    expect(wrapper.text()).toContain('还没有收藏课程')
  })
})
