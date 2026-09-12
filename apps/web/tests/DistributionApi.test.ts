// @vitest-environment happy-dom

import { beforeEach, describe, expect, it, vi } from 'vitest';

const http = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  delete: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http }));

import {
  createShareEntry,
  fetchDistributionStatus,
  fetchMyCommissions,
  fetchMyDownline,
  fetchMyShareEntries,
  revokeShareEntry,
} from '@/api/distribution';

const okEnvelope = (data: unknown) => ({ ok: true, data });

const commissionPayload = () => ({
  items: [
    {
      id: 11,
      order_id: 9001,
      course_id: 5,
      course_title: '课程',
      referee_masked_phone: '138****5678',
      level: 1,
      amount_cents: 1000,
      status: 'pending',
      source: 'system_settle',
      created_at: '2026-09-10T00:00:00+08:00',
      settled_at: null,
      voided_at: null,
      void_reason: null,
    },
  ],
  total: 1,
  page: 1,
  limit: 20,
  summary: { pending_cents: 1000, settled_cents: 0, voided_cents: 0, total_cents: 1000 },
});

describe('distribution api wrappers (T062)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('parses responses through Zod and returns typed data', async () => {
    http.get.mockImplementation(async (url: string) => {
      if (url === '/distribution/status') {
        return { data: okEnvelope({ enabled: true, learner_can_view_detail: false }) };
      }
      if (url === '/distribution/share-entries') {
        return { data: okEnvelope({ items: [] }) };
      }
      if (url === '/distribution/commissions') {
        return { data: okEnvelope(commissionPayload()) };
      }
      return { data: okEnvelope({ items: [] }) }; // downline
    });

    const status = await fetchDistributionStatus();
    expect(status).toEqual({ enabled: true, learner_can_view_detail: false });

    const shares = await fetchMyShareEntries();
    expect(shares.items).toEqual([]);

    const commissions = await fetchMyCommissions({ page: 2, limit: 50 });
    expect(commissions.total).toBe(1);
    expect(http.get).toHaveBeenCalledWith('/distribution/commissions', {
      params: { page: 2, limit: 50 },
    });

    const downline = await fetchMyDownline(2);
    expect(downline.items).toEqual([]);
    expect(http.get).toHaveBeenCalledWith('/distribution/downline', { params: { level: 2 } });
  });

  it('rejects invalid create input before any request goes out', async () => {
    await expect(createShareEntry({ scope: 'bogus' as never })).rejects.toThrow();
    expect(http.post).not.toHaveBeenCalled();

    // course_id must be a positive integer per ShareEntryCreateInput.
    await expect(createShareEntry({ scope: 'course', course_id: 0 })).rejects.toThrow();
    expect(http.post).not.toHaveBeenCalled();
  });

  it('turns an error envelope into a coded Error instead of leaking undefined access', async () => {
    http.get.mockResolvedValue({
      data: {
        ok: false,
        data: null,
        error: { code: 'FORBIDDEN', message: 'DISTRIBUTION_DISABLED' },
      },
    });
    await expect(fetchMyShareEntries()).rejects.toMatchObject({ code: 'FORBIDDEN' });
    await expect(fetchMyCommissions()).rejects.toMatchObject({ code: 'FORBIDDEN' });
    await expect(fetchMyDownline()).rejects.toMatchObject({ code: 'FORBIDDEN' });
    await expect(fetchDistributionStatus()).rejects.toMatchObject({ code: 'FORBIDDEN' });
  });

  it('fails loudly when a payload misses required fields (no silent pass-through)', async () => {
    http.get.mockResolvedValue({ data: okEnvelope({ items: [] }) });
    // commissions requires summary — a shape regression must be caught here.
    await expect(fetchMyCommissions()).rejects.toThrow();
  });

  it('revoke posts the delete and expects the ok envelope', async () => {
    http.delete.mockResolvedValue({ data: okEnvelope({ ok: true }) });
    await expect(revokeShareEntry(7)).resolves.toEqual({ ok: true });
    expect(http.delete).toHaveBeenCalledWith('/distribution/share-entries/7');
  });
});
