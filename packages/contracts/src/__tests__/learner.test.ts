import { describe, expect, it } from 'vitest'
import { LearnerAccountDTO } from '../learner.js'

describe('learner account summary contract', () => {
  it('contains learning and successful purchase aggregates', () => {
    const parsed = LearnerAccountDTO.parse({
      account_id: 7,
      login: '13912345678',
      display_name: '小王',
      department_id: null,
      department_name: '',
      status: 'active',
      must_change_password: false,
      last_login_at: null,
      created_at: '2026-08-28 10:00:00',
      course_count: 3,
      completed_course_count: 1,
      successful_order_count: 2,
      total_paid_amount: 198,
    })

    expect(parsed.course_count).toBe(3)
    expect(parsed.completed_course_count).toBe(1)
    expect(parsed.successful_order_count).toBe(2)
    expect(parsed.total_paid_amount).toBe(198)
  })
})
