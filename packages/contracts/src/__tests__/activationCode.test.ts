import { describe, expect, it } from 'vitest'
import {
  ActivationCodeBatchCreatedDTO,
  AdminActivationCodeListDTO,
  CreateActivationCodeBatchInput,
  RedeemActivationCodeInput,
  RedeemActivationCodeResultDTO,
} from '../activationCode.js'

describe('activation code contracts', () => {
  it('validates batch quantity and nullable expiry', () => {
    expect(CreateActivationCodeBatchInput.safeParse({ quantity: 1, expires_at: null }).success).toBe(true)
    expect(CreateActivationCodeBatchInput.safeParse({ quantity: 1000, expires_at: '2026-12-31T23:59:59+08:00' }).success).toBe(true)
    expect(CreateActivationCodeBatchInput.safeParse({ quantity: 0, expires_at: null }).success).toBe(false)
    expect(CreateActivationCodeBatchInput.safeParse({ quantity: 1001, expires_at: null }).success).toBe(false)
  })

  it('parses the one-time plaintext batch response', () => {
    const parsed = ActivationCodeBatchCreatedDTO.parse({
      id: 9,
      course_id: 42,
      quantity: 2,
      expires_at: null,
      created_at: '2026-09-02T10:00:00+08:00',
      codes: ['AB3D-EFGH-JKMN-PQRS', 'CD4E-FGHJ-KMNP-QRST'],
    })
    expect(parsed.codes).toHaveLength(2)
  })

  it('parses only masked codes from admin list responses', () => {
    const parsed = AdminActivationCodeListDTO.parse({
      items: [{
        id: 101,
        batch_id: 9,
        course_id: 42,
        display_code: 'AB3D****PQRS',
        status: 'unused',
        expires_at: null,
        redeemed_by: null,
        redeemed_at: null,
        voided_at: null,
        created_at: '2026-09-02T10:00:00+08:00',
      }],
      total: 1,
      page: 1,
      limit: 20,
    })
    expect(parsed.items[0]!.display_code).toBe('AB3D****PQRS')
    expect(AdminActivationCodeListDTO.safeParse({
      ...parsed,
      items: [{ ...parsed.items[0], display_code: 'AB3D-EFGH-JKMN-PQRS' }],
    }).success).toBe(false)
  })

  it('parses learner redeem request and result', () => {
    expect(RedeemActivationCodeInput.safeParse({ code: 'AB3D-EFGH-JKMN-PQRS' }).success).toBe(true)
    expect(RedeemActivationCodeResultDTO.parse({
      granted: true,
      course_id: 42,
      course_title: '示例课',
      source: 'activation_code',
    }).source).toBe('activation_code')
  })
})
