// @vitest-environment happy-dom

import { mount } from '@vue/test-utils'
import ElementPlus from 'element-plus'
import { describe, expect, it, vi } from 'vitest'

const authApi = vi.hoisted(() => ({
  clearTokens: vi.fn(),
  permissionCodes: vi.fn(() => ['*']),
}))

const routerApi = vi.hoisted(() => ({
  route: { path: '/', meta: { title: '工作台' } },
  push: vi.fn(),
}))

vi.mock('@/api/http', () => ({
  clearTokens: authApi.clearTokens,
  http: { post: vi.fn() },
  permissionCodes: authApi.permissionCodes,
}))

vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}))

import AdminLayout from '@/layouts/AdminLayout.vue'

describe('AdminLayout', () => {
  it('renders an SVG icon for every visible menu item', () => {
    const wrapper = mount(AdminLayout, {
      global: {
        plugins: [ElementPlus],
        stubs: { 'router-view': true },
      },
    })

    const menuItems = wrapper.findAll('.el-menu-item')
    const submenuTitles = wrapper.findAll('.el-sub-menu__title')

    expect(menuItems).toHaveLength(14)
    expect(submenuTitles).toHaveLength(1)
    expect(menuItems.every((item) => item.find('svg').exists())).toBe(true)
    expect(submenuTitles.every((title) => title.find('svg').exists())).toBe(true)
  })
})
