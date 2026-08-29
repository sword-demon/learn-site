// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import type { LessonDeliveryDTO, PublicCourseDetailDTO } from '@learn-site/contracts'

const learnerApi = vi.hoisted(() => ({
  fetchLesson: vi.fn(),
  fetchCourseDetail: vi.fn(),
}))
const progressApi = vi.hoisted(() => ({
  reportDocumentOpen: vi.fn(),
  completeDocument: vi.fn(),
  heartbeat: vi.fn(),
}))
const routeApi = vi.hoisted(() => ({ params: { courseId: '9', lessonId: '7' } }))

vi.mock('@/api/learner', () => learnerApi)
vi.mock('@/composables/useLearningProgress', () => ({
  useLearningProgress: () => progressApi,
}))
vi.mock('vue-router', () => ({
  useRoute: () => routeApi,
  useRouter: () => ({ push: vi.fn() }),
}))

import LessonView from '@/views/learn/LessonView.vue'

const course: PublicCourseDetailDTO = {
  course: {
    id: 9, category_id: 1, category_name: '工程实践', title: 'Vue 组件设计', cover_url: null,
    teacher_name: '林老师', summary: '', intro_html: '', price_mode: 'free', list_price: 0,
    sale_price: 0, sale_start_at: null, sale_end_at: null, viewer_authorized: true,
    viewer_entitlement_status: 'active', viewer_entitlement_source: 'free',
    viewer_revoked_reason: null, viewer_can_rejoin: false,
    learner_count: 1, created_at: '2026-08-28 10:00:00',
  },
  chapters: [{ id: 3, course_id: 9, title: '基础', sort: 0, lessons: [{
    id: 7, title: '状态建模', sort: 0, content_type: 'markdown', duration_seconds: 0,
    is_preview: false, locked: false,
  }] }],
}

describe('LessonView', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    learnerApi.fetchCourseDetail.mockResolvedValue(course)
    progressApi.reportDocumentOpen.mockResolvedValue({ lesson_id: 7, position_seconds: 1, completed: false, completed_at: null, opened_at: '2026-08-28 10:00:00' })
    progressApi.completeDocument.mockResolvedValue({ lesson_id: 7, position_seconds: 1, completed: true, completed_at: '2026-08-28 10:01:00', opened_at: '2026-08-28 10:00:00' })
  })

  it('renders a markdown lesson through the renderer component and records open', async () => {
    const delivery: LessonDeliveryDTO = { kind: 'markdown', html: '<h1>状态机</h1>' }
    learnerApi.fetchLesson.mockResolvedValue(delivery)
    const wrapper = mount(LessonView, {
      global: {
        stubs: {
          RouterLink: { template: '<a><slot /></a>' },
          MarkdownRenderer: { props: ['html'], template: '<div data-role="markdown" v-html="html" />' },
          QuestionPanel: true,
        },
      },
    })
    await flushPromises()

    expect(wrapper.get('[data-role="markdown"]').html()).toContain('状态机')
    expect(progressApi.reportDocumentOpen).toHaveBeenCalledWith('markdown')
  })

  it('shows the access error without rendering protected content', async () => {
    learnerApi.fetchLesson.mockRejectedValue(Object.assign(new Error('FORBIDDEN'), { code: 'FORBIDDEN' }))
    const wrapper = mount(LessonView, {
      global: { stubs: { RouterLink: { template: '<a><slot /></a>' }, QuestionPanel: true } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('需要先获得访问权')
    expect(wrapper.find('[data-role="markdown"]').exists()).toBe(false)
  })
})
