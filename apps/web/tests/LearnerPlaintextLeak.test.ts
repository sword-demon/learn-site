// @vitest-environment happy-dom

import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * T054 — every /api/learner/distribution/* response shape is guarded by a
 * Zod MaskedPhone regex, so a backend that leaks a plaintext 11-digit phone
 * must fail parsing instead of flowing into the UI. These tests pin that:
 * masked payloads pass through with zero plaintext matches, and planted
 * plaintext phones are rejected.
 */

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
} from '@/api/distribution';

const LEARNER_DISTRIBUTION_ENDPOINTS = [
  '/distribution/status',
  '/distribution/share-entries',
  '/distribution/commissions',
  '/distribution/downline',
] as const;

const okEnvelope = (data: unknown) => ({ ok: true, data });

function masked(phone: string): string {
  return `${phone.slice(0, 3)}****${phone.slice(-4)}`;
}

describe('learner distribution responses never carry a plaintext phone', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('masked payloads parse cleanly with zero 11-digit matches', async () => {
    const phone1 = '13812345678';
    const phone2 = '15987654321';

    http.get.mockImplementation(async (url: string) => {
      if (url === '/distribution/status') {
        return { data: okEnvelope({ enabled: true, learner_can_view_detail: true }) };
      }
      if (url === '/distribution/share-entries') {
        return {
          data: okEnvelope({
            items: [
              {
                id: 1,
                scope: 'site',
                course_id: null,
                masked_code: 'ABCD****WXYZ',
                created_at: '2026-09-10T00:00:00+08:00',
                distribution_enabled_at_creation: true,
                revoked_at: null,
                visit_count: 3,
                bound_count: 1,
              },
            ],
          }),
        };
      }
      if (url === '/distribution/commissions') {
        return {
          data: okEnvelope({
            items: [
              {
                id: 11,
                order_id: 9001,
                course_id: 5,
                course_title: '课程',
                referee_masked_phone: masked(phone1),
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
          }),
        };
      }
      // /distribution/downline
      return {
        data: okEnvelope({
          items: [
            {
              learner_id: 2,
              masked_phone: masked(phone2),
              level: 1,
              registered_at: '2026-09-10T00:00:00+08:00',
            },
          ],
        }),
      };
    });

    const status = await fetchDistributionStatus();
    const shares = await fetchMyShareEntries();
    const commissions = await fetchMyCommissions({ page: 1, limit: 20 });
    const downline = await fetchMyDownline();

    for (const payload of [status, shares, commissions, downline]) {
      expect(JSON.stringify(payload)).not.toMatch(/1[3-9]\d{9}/);
    }
    // Every endpoint the learner distribution page touches was exercised.
    const calledUrls = http.get.mock.calls.map((call) => call[0]);
    for (const url of LEARNER_DISTRIBUTION_ENDPOINTS) {
      expect(calledUrls).toContain(url);
    }
  });

  it('a planted plaintext phone fails the MaskedPhone schema instead of rendering', async () => {
    const { CommissionRecordDTO } = await import('@learn-site/contracts');
    const leaked = {
      id: 11,
      order_id: 9001,
      course_id: 5,
      course_title: '课程',
      referee_masked_phone: '13812345678',
      level: 1,
      amount_cents: 1000,
      status: 'pending',
      source: 'system_settle',
      created_at: '2026-09-10T00:00:00+08:00',
      settled_at: null,
      voided_at: null,
      void_reason: null,
    };
    expect(CommissionRecordDTO.safeParse(leaked).success).toBe(false);
    expect(
      CommissionRecordDTO.safeParse({ ...leaked, referee_masked_phone: '138****5678' }).success,
    ).toBe(true);

    const { DownlineEntryDTO } = await import('@learn-site/contracts');
    expect(
      DownlineEntryDTO.safeParse({
        learner_id: 2,
        masked_phone: '15987654321',
        level: 1,
        registered_at: '2026-09-10T00:00:00+08:00',
      }).success,
    ).toBe(false);
  });

  it('creating a share entry returns the plaintext code only inside the mint response', async () => {
    http.post.mockResolvedValue({
      data: okEnvelope({
        id: 3,
        plaintext_code: 'ABCDEFGHJKMNPQ',
        share_url: 'https://learn.example.test/r/ABCDEFGHJKMNPQ',
        created_at: '2026-09-10T00:00:00+08:00',
      }),
    });
    http.delete.mockResolvedValue({ data: okEnvelope({ ok: true }) });

    const created = await createShareEntry({ scope: 'site' });
    expect(created.share_url).toContain(created.plaintext_code);
    expect(JSON.stringify(created)).not.toMatch(/1[3-9]\d{9}/);

    http.get.mockResolvedValue({
      data: okEnvelope({
        items: [],
        total: 0,
        page: 1,
        limit: 20,
        summary: { pending_cents: 0, settled_cents: 0, voided_cents: 0, total_cents: 0 },
      }),
    });
    await expect(fetchMyCommissions({ page: 99, limit: 20 })).resolves.toBeTruthy();
    expect(http.get).toHaveBeenLastCalledWith('/distribution/commissions', {
      params: { page: 99, limit: 20 },
    });
  });
});
