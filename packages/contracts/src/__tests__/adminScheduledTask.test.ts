import { describe, expect, it } from 'vitest';
import {
  AdminScheduledTaskDTO,
  AdminScheduledTaskRunDetailDTO,
  AdminScheduledTaskRunListDTO,
  ScheduledTaskRunSummaryDTO,
  UpdateScheduledTaskBody,
  ValidateExpressionBody,
  ValidateExpressionResultDTO,
} from '../adminScheduledTask.js'

describe('adminScheduledTask contracts', () => {
  it('accepts scheduled task list item', () => {
    const parsed = AdminScheduledTaskDTO.parse({
      id: 1,
      handler_code: 'notification.cleanup',
      name: '学员消息收件箱过期清理',
      description: '删除创建时间超过 2 个月的学员收件箱记录',
      schedule_expression: '0 30 3 * * *',
      enabled: true,
      params: { batch_size: 500 },
      handler_status: 'available',
      last_run_at: '2026-08-29 03:30:00',
      last_run_status: 'success',
      next_run_at: '2026-08-30 03:30:00',
      updated_at: '2026-08-28 10:00:00',
    })
    expect(parsed.handler_code).toBe('notification.cleanup')
  })

  it('accepts validate expression result', () => {
    const parsed = ValidateExpressionResultDTO.parse({
      valid: true,
      next_run_at: '2026-08-30 03:30:00',
      error: null,
    })
    expect(parsed.valid).toBe(true)
  })

  it('accepts run list and update body', () => {
    AdminScheduledTaskRunListDTO.parse({
      items: [
        {
          id: 42,
          task_id: 1,
          task_name: '清理',
          trigger_type: 'schedule',
          status: 'success',
          started_at: '2026-08-29 03:30:00',
          finished_at: '2026-08-29 03:30:02',
          duration_ms: 1800,
          error_message: null,
          actor_staff_id: null,
          actor_login: null,
        },
      ],
      total: 1,
      page: 1,
      per_page: 20,
    })
    UpdateScheduledTaskBody.parse({ enabled: false })
    ValidateExpressionBody.parse({ schedule_expression: '0 30 3 * * *' })
    ScheduledTaskRunSummaryDTO.parse({
      run_id: 42,
      task_id: 1,
      trigger_type: 'manual',
      status: 'success',
      started_at: '2026-08-29 15:00:00',
      finished_at: '2026-08-29 15:00:02',
      duration_ms: 2100,
    })
    AdminScheduledTaskRunDetailDTO.parse({
      id: 42,
      task_id: 1,
      task_name: '清理',
      trigger_type: 'manual',
      status: 'failed',
      started_at: '2026-08-29 15:00:00',
      finished_at: '2026-08-29 15:00:01',
      duration_ms: 900,
      error_message: 'boom',
      actor_staff_id: 3,
      actor_login: 'admin',
      context: { deleted: 0 },
    })
  })
})
