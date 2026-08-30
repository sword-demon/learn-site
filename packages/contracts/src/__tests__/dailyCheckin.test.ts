import { describe, expect, it } from 'vitest'
import {
  AdminCheckinDetailDTO,
  AdminCheckinListDTO,
  CreateCheckinInput,
  LearnerCheckinDTO,
  LearnerCheckinListDTO,
  LearnerTodayCheckinDTO,
} from '../dailyCheckin.js'

describe('dailyCheckin contracts', () => {
  it('parses create check-in input', () => {
    expect(CreateCheckinInput.parse({ plan_html: '<p>今日计划</p>' }).plan_html).toBe(
      '<p>今日计划</p>',
    )
    expect(CreateCheckinInput.safeParse({ plan_html: '' }).success).toBe(false)
  })

  it('parses learner today status', () => {
    const dto = LearnerTodayCheckinDTO.parse({
      server_date: '2026-08-30',
      checked_in: true,
      record: {
        id: 1,
        checkin_date: '2026-08-30',
        plan_html: '<p>计划</p>',
        checked_in_at: '2026-08-30T09:00:00+08:00',
      },
    })
    expect(dto.checked_in).toBe(true)
    expect(dto.record?.id).toBe(1)
  })

  it('parses learner list and admin detail envelopes', () => {
    const list = LearnerCheckinListDTO.parse({
      items: [
        {
          id: 1,
          checkin_date: '2026-08-30',
          plan_html: '<p>计划</p>',
          checked_in_at: '2026-08-30T09:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    })
    expect(list.items).toHaveLength(1)

    const adminList = AdminCheckinListDTO.parse({
      items: [
        {
          id: 1,
          learner_id: 10,
          learner_display_name: '小明',
          learner_phone_masked: '138****5678',
          checkin_date: '2026-08-30',
          plan_summary: '今日计划',
          checked_in_at: '2026-08-30T09:00:00+08:00',
        },
      ],
      total: 1,
      page: 1,
      limit: 20,
    })
    expect(adminList.items[0]?.learner_phone_masked).toContain('****')

    const detail = AdminCheckinDetailDTO.parse({
      ...adminList.items[0],
      plan_html: '<p>今日计划</p>',
    })
    expect(detail.plan_html).toContain('今日计划')
  })

  it('parses learner check-in dto', () => {
    const row = LearnerCheckinDTO.parse({
      id: 2,
      checkin_date: '2026-08-29',
      plan_html: '<p>昨日</p>',
      checked_in_at: '2026-08-29T08:00:00+08:00',
    })
    expect(row.checkin_date).toBe('2026-08-29')
  })

  it('rejects database datetime strings that are not ISO 8601', () => {
    expect(
      LearnerCheckinDTO.safeParse({
        id: 2,
        checkin_date: '2026-08-29',
        plan_html: '<p>昨日</p>',
        checked_in_at: '2026-08-29 08:00:00',
      }).success,
    ).toBe(false)
  })
})
