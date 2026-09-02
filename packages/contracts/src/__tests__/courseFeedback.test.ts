import { describe, expect, it } from 'vitest'
import {
  AdminCourseFeedbackDetailDTO,
  AdminCourseFeedbackListDTO,
  CourseFeedbackCreatedDTO,
  SubmitCourseFeedbackInput,
  UpdateCourseFeedbackStatusInput,
} from '../courseFeedback.js'

describe('course feedback contracts', () => {
  it('requires a non-empty body within the 20k boundary', () => {
    expect(SubmitCourseFeedbackInput.safeParse({ body_html: '<p>建议</p>' }).success).toBe(true)
    expect(SubmitCourseFeedbackInput.safeParse({ body_html: '' }).success).toBe(false)
    expect(SubmitCourseFeedbackInput.safeParse({ body_html: '字'.repeat(20_001) }).success).toBe(false)
  })

  it('parses learner creation result', () => {
    expect(CourseFeedbackCreatedDTO.parse({
      id: 7,
      course_id: 42,
      status: 'pending',
      created_at: '2026-09-02T11:00:00+08:00',
    }).status).toBe('pending')
  })

  it('parses admin list and detail responses', () => {
    const list = AdminCourseFeedbackListDTO.parse({
      items: [{
        id: 7,
        course_id: 42,
        learner: { account_id: 101, nickname: '小明' },
        body_excerpt: '希望增加练习…',
        status: 'pending',
        created_at: '2026-09-02T11:00:00+08:00',
        processed_at: null,
      }],
      total: 1,
      page: 1,
      limit: 20,
    })
    expect(list.items[0]!.learner.account_id).toBe(101)

    const detail = AdminCourseFeedbackDetailDTO.parse({
      ...list.items[0],
      body_html: '<p>希望增加练习</p>',
      processed_by_staff_id: null,
    })
    expect(detail.body_html).toContain('增加练习')
  })

  it('locks status changes to pending or processed', () => {
    expect(UpdateCourseFeedbackStatusInput.safeParse({ status: 'processed' }).success).toBe(true)
    expect(UpdateCourseFeedbackStatusInput.safeParse({ status: 'closed' }).success).toBe(false)
  })
})
