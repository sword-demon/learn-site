// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { CourseDTO } from '@learn-site/contracts'
import { ElMessage, ElMessageBox } from 'element-plus'
import { installElementPlus } from '@/plugins/element-plus'

const catalogApi = vi.hoisted(() => ({
  deleteCourse: vi.fn(),
  listCourses: vi.fn(),
  publishCourse: vi.fn(),
  unpublishCourse: vi.fn(),
}))
const routerApi = vi.hoisted(() => ({ push: vi.fn() }))

vi.mock('@/api/catalog', () => catalogApi)
vi.mock('vue-router', () => ({ useRouter: () => routerApi }))

import CourseListView from '@/views/catalog/CourseListView.vue'

const course: CourseDTO = {
  id: 12,
  department_id: 2,
  category_id: 3,
  title: '可删除课程',
  cover_url: null,
  teacher_name: '林老师',
  summary: '课程删除测试',
  intro_rich_text: '<p>课程删除测试</p>',
  status: 'draft',
  price_mode: 'free',
  list_price: 0,
  sale_price: 0,
  sale_start_at: null,
  sale_end_at: null,
  created_by_staff_id: 7,
  created_at: '2026-08-28 10:00:00',
  updated_at: '2026-08-28 10:00:00',
}

describe('CourseListView deletion', () => {
  beforeEach(() => {
    vi.restoreAllMocks()
    vi.clearAllMocks()
    catalogApi.listCourses.mockResolvedValue({
      items: [course],
      total: 1,
      page: 1,
      limit: 20,
    })
  })

  it('requires confirmation and explains an order blocker', async () => {
    const confirm = vi.spyOn(ElMessageBox, 'confirm').mockResolvedValue(undefined as never)
    const error = vi.spyOn(ElMessage, 'error').mockImplementation(() => ({ close: () => undefined }))
    catalogApi.deleteCourse.mockRejectedValue({
      response: {
        data: {
          error: { code: 'CONFLICT', message: 'COURSE_HAS_ORDERS' },
        },
      },
    })
    const wrapper = mount(CourseListView, {
      global: { plugins: [installElementPlus] },
    })
    await flushPromises()

    const deleteButton = wrapper.findAll('button').find((button) => button.text() === '删除')
    expect(deleteButton).toBeDefined()
    await deleteButton?.trigger('click')
    await flushPromises()

    expect(confirm).toHaveBeenCalledWith(
      '确定删除「可删除课程」吗？此操作不可撤销。',
      '删除',
      { type: 'warning' },
    )
    expect(catalogApi.deleteCourse).toHaveBeenCalledWith(12)
    expect(error).toHaveBeenCalledWith('课程已有订单，无法删除')
  })
})
