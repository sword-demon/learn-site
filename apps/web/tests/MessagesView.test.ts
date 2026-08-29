// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'

const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  markNotificationRead: vi.fn(),
}))

vi.mock('@/api/notifications', () => notificationsApi)

import MessagesView from '@/views/me/MessagesView.vue'

describe('MessagesView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    notificationsApi.listNotifications.mockResolvedValue({
      items: [
        {
          id: 1,
          kind: 'question_update',
          title: '问题有新回复',
          body: '管理员回复了你的问题',
          resource_type: 'question',
          resource_id: 12,
          resource_available: true,
          payload: { course_id: 5 },
          read: false,
          created_at: '2026-08-28 10:00:00',
        },
        {
          id: 2,
          kind: 'progress_reset',
          title: '学习进度已重置',
          body: null,
          resource_type: 'course',
          resource_id: 5,
          resource_available: true,
          payload: null,
          read: true,
          created_at: '2026-08-28 09:30:00',
        },
        {
          id: 3,
          kind: 'entitlement_revoked',
          title: '课程授权已撤销',
          body: null,
          resource_type: 'course',
          resource_id: 5,
          resource_available: false,
          payload: null,
          read: true,
          created_at: '2026-08-28 09:00:00',
        },
      ],
      total: 3,
      page: 1,
      limit: 20,
    })
    notificationsApi.markNotificationRead.mockResolvedValue({ read: true })
  })

  it('renders messages and marks an unread item as read', async () => {
    const wrapper = mount(MessagesView, {
      global: { stubs: { RouterLink: { props: ['to'], template: '<a><slot /></a>' } } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('问题有新回复')
    expect(wrapper.text()).toContain('学习进度已重置')
    expect(wrapper.text()).toContain('课程授权')
    expect(wrapper.text()).toContain('关联内容已不可用')
    await wrapper.get('button[data-read-id="1"]').trigger('click')
    await flushPromises()
    expect(notificationsApi.markNotificationRead).toHaveBeenCalledWith(1)
    expect(wrapper.find('button[data-read-id="1"]').exists()).toBe(false)
  })
})
