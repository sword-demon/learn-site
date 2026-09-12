// @vitest-environment happy-dom

import { beforeEach, describe, expect, it, vi } from 'vitest';

const http = vi.hoisted(() => ({
  get: vi.fn(),
  put: vi.fn(),
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http }));

import {
  exportCommissionsCsv,
  fetchAudit,
  fetchCommissions,
  fetchCourseOverrides,
  fetchDistributionConfig,
  fetchReconcileByOrder,
  saveCourseOverride,
  saveDistributionConfig,
  voidCommission,
} from '@/api/distribution';

const okEnvelope = (data: unknown) => ({ ok: true, data });

const cfg = () => ({
  enabled: true,
  level_cap: 3 as const,
  level1_pct: 0.1,
  level2_pct: 0.05,
  level3_pct: 0.02,
  base: 'order_paid' as const,
  per_order_cap_cents: 5000,
  per_learner_course_cap_cents: 20000,
  per_learner_total_cap_cents: null,
  settlement: 'order_settled_after_refund_window' as const,
  refund_void_rule: 'void_all' as const,
  payout_form: 'cash_record_only' as const,
  learner_can_view_detail: true,
  updated_at: '2026-09-10T00:00:00+08:00',
  updated_by: 1,
});

const overrideRow = () => ({
  course_id: 5,
  enabled: false,
  level1_pct: null,
  level2_pct: null,
  level3_pct: null,
  per_order_cap_cents: null,
  per_learner_course_cap_cents: null,
  updated_at: '2026-09-10T00:00:00+08:00',
  updated_by: 1,
});

const commissionRow = () => ({
  id: 11,
  order_id: 9001,
  course_id: 5,
  course_title: '课程',
  referee_masked_phone: '138****5678',
  level: 1 as const,
  amount_cents: 1000,
  status: 'pending' as const,
  source: 'system_settle' as const,
  created_at: '2026-09-10T00:00:00+08:00',
  settled_at: null,
  voided_at: null,
  void_reason: null,
});

describe('admin distribution api wrappers (T074)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('fetch/save config round-trips through Zod', async () => {
    http.get.mockResolvedValue({ data: okEnvelope(cfg()) });
    const fetched = await fetchDistributionConfig();
    expect(fetched.level_cap).toBe(3);
    expect(http.get).toHaveBeenCalledWith('/distribution/config');

    http.put.mockResolvedValue({ data: okEnvelope(cfg()) });
    const saved = await saveDistributionConfig({
      enabled: true,
      level_cap: 2,
      level1_pct: 0.1,
      level2_pct: 0.05,
      level3_pct: 0,
      base: 'order_paid',
      per_order_cap_cents: 5000,
      per_learner_course_cap_cents: 20000,
      per_learner_total_cap_cents: null,
      settlement: 'order_settled_after_refund_window',
      refund_void_rule: 'void_all',
      payout_form: 'cash_record_only',
      learner_can_view_detail: true,
    });
    expect(saved.level_cap).toBe(3);
    expect(http.put).toHaveBeenCalledWith(
      '/distribution/config',
      expect.objectContaining({ level_cap: 2 }),
    );
  });

  it('rejects level_cap=4 before any request is sent', async () => {
    const { updated_at: _at, updated_by: _by, ...input } = cfg();
    await expect(saveDistributionConfig({ ...input, level_cap: 4 as never })).rejects.toThrow();
    expect(http.put).not.toHaveBeenCalled();
  });

  it('course overrides list and save pass through the envelope', async () => {
    http.get.mockResolvedValue({
      data: okEnvelope({ items: [overrideRow()], total: 1, page: 1, limit: 20 }),
    });
    const list = await fetchCourseOverrides(1, 20);
    expect(list.total).toBe(1);
    expect(list.items[0]?.course_id).toBe(5);
    expect(http.get).toHaveBeenCalledWith('/distribution/course-overrides', {
      params: { page: 1, limit: 20 },
    });

    http.put.mockResolvedValue({ data: okEnvelope({ ...overrideRow(), enabled: true }) });
    const saved = await saveCourseOverride(5, { enabled: true });
    expect(saved.enabled).toBe(true);
    expect(http.put).toHaveBeenCalledWith(
      '/distribution/course-overrides/5',
      expect.objectContaining({ enabled: true }),
    );
  });

  it('reconcile by order returns receivers or null on NOT_FOUND', async () => {
    http.get.mockResolvedValueOnce({
      data: okEnvelope({
        order_id: 9001,
        course_id: 5,
        order_paid_cents_snapshot: 100000,
        config_snapshot: cfg(),
        receivers: [
          {
            id: 11,
            referrer_learner_id: 42,
            referrer_masked_phone: '138****5678',
            level: 1,
            amount_cents: 10000,
            status: 'pending',
            source: 'system_settle',
            settled_at: null,
            voided_at: null,
            void_reason: null,
          },
        ],
      }),
    });
    const hit = await fetchReconcileByOrder(9001);
    expect(hit?.receivers).toHaveLength(1);
    expect(JSON.stringify(hit)).not.toMatch(/1[3-9]\d{9}/);

    const notFound = Object.assign(new Error('REQUEST'), {
      response: {
        data: {
          ok: false,
          data: null,
          error: { code: 'NOT_FOUND', message: 'ORDER_COMMISSION_NOT_FOUND' },
        },
      },
    });
    http.get.mockRejectedValueOnce(notFound);
    await expect(fetchReconcileByOrder(9002)).resolves.toBeNull();
  });

  it('commissions list, void (min reason enforced client-side) and audit fetch', async () => {
    http.get.mockImplementation(async (url: string) => {
      if (url === '/distribution/commissions') {
        return { data: okEnvelope({ items: [commissionRow()], total: 1, page: 1, limit: 20 }) };
      }
      return {
        data: okEnvelope({
          items: [
            {
              id: 1,
              actor_type: 'system',
              actor_id: null,
              action: 'commission.settle',
              subject_type: 'commission',
              subject_id: 9001,
              before_json: null,
              after_json: { receivers: [42] },
              reason: null,
              created_at: '2026-09-10T00:00:00+08:00',
            },
          ],
          total: 1,
          page: 1,
          limit: 20,
        }),
      };
    });

    const commissions = await fetchCommissions({ order_id: 9001 });
    expect(commissions.items[0]?.referee_masked_phone).toBe('138****5678');
    expect(http.get).toHaveBeenCalledWith('/distribution/commissions', {
      params: { order_id: 9001 },
    });

    const audit = await fetchAudit({ action: 'commission.settle' });
    expect(audit.items[0]?.action).toBe('commission.settle');
    expect(http.get).toHaveBeenCalledWith('/distribution/audit', {
      params: { action: 'commission.settle' },
    });

    http.post.mockResolvedValue({
      data: okEnvelope({ ...commissionRow(), status: 'voided', source: 'admin_void' }),
    });
    await expect(voidCommission(11, 'abc')).rejects.toThrow();
    expect(http.post).not.toHaveBeenCalled();

    const voided = await voidCommission(11, '违规推广撤销');
    expect(voided.status).toBe('voided');
    expect(http.post).toHaveBeenCalledWith(
      '/distribution/commissions/11/void',
      expect.objectContaining({ reason: '违规推广撤销' }),
    );
  });

  it('csv export returns the raw text with masked phones only', async () => {
    http.get.mockResolvedValue({
      data: 'id,order_id,course_id,referrer_masked_phone,level,amount_cents,status\n11,9001,5,138****5678,1,1000,pending',
    });
    const csv = await exportCommissionsCsv({ status: 'pending' });
    expect(csv).toContain('138****5678');
    expect(csv).not.toMatch(/1[3-9]\d{9}/);
    expect(http.get).toHaveBeenCalledWith('/distribution/commissions/export', {
      params: { status: 'pending' },
      responseType: 'text',
    });
  });
});
