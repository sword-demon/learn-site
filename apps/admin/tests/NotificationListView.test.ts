// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import { installElementPlus } from '@/plugins/element-plus'

const notificationsApi = vi.hoisted(() => ({
  listNotifications: vi.fn(),
  getNotification: vi.fn(),
}))

vi.mock('@/api/notifications', () => notificationsApi)

import NotificationListView from '@/views/notifications/NotificationListView.vue'

describe('NotificationListView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    notificationsApi.listNotifications.mockResolvedValue({
      items: [
        {
          id: 1,
          type: 'announcement',
          title: '维护通知',
          sender_staff_id: 2,
          sender_login: 'admin',
          recipient_summary: '全体学员',
          recipient_count: 3,
          created_at: '2026-08-29 10:00:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    })
    notificationsApi.getNotification.mockResolvedValue({
      id: 1,
      type: 'announcement',
      title: '维护通知',
      body: '今晚维护',
      sender_staff_id: 2,
      sender_login: 'admin',
      recipient_summary: '全体学员',
      recipient_count: 3,
      created_at: '2026-08-29 10:00:00',
    })
  })

  it('renders notification list and opens compose dialog', async () => {
    const wrapper = mount(NotificationListView, {
      global: {
        plugins: [installElementPlus],
        stubs: { NotificationComposeDialog: true },
      },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('通知管理')
    expect(wrapper.text()).toContain('维护通知')
    expect(wrapper.text()).toContain('全体学员')
    await wrapper.get('.page-head button.el-button--primary').trigger('click')
    expect(wrapper.findComponent({ name: 'NotificationComposeDialog' }).exists()).toBe(true)
  })
})
