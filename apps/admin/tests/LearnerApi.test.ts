import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp, http: mockHttp }));

import { listLearners } from '@/api/learners';

const account = {
  account_id: 1,
  login: 'alice',
  display_name: 'Alice',
  department_id: 1,
  department_name: '工程',
  status: 'active' as const,
  must_change_password: false,
  last_login_at: null,
  created_at: '2026-08-27 10:00:00',
  course_count: 3,
  completed_course_count: 1,
  successful_order_count: 2,
  total_paid_amount: 198,
};

describe('learner API boundary (repro)', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('listLearners: parses a well-formed ok envelope', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: { items: [account], total: 1, page: 1, limit: 20 },
      },
    });
    await expect(listLearners({ page: 1, limit: 20 })).resolves.toEqual({
      items: [account],
      total: 1,
      page: 1,
      limit: 20,
    });
    expect(mockHttp.get).toHaveBeenCalledWith('/learners', { params: { page: 1, limit: 20 } });
  });

  it('listLearners: reproduces reported union error on empty/odd response', async () => {
    mockHttp.get.mockResolvedValueOnce({ data: {} });
    let caught: unknown;
    try {
      await listLearners();
    } catch (e) {
      caught = e;
    }
    expect(caught).toBeInstanceOf(Error);
  });

  it('listLearners: parses a real UNAUTHENTICATED error envelope', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: false,
        data: null,
        error: { code: 'UNAUTHENTICATED', message: 'UNAUTHENTICATED' },
        meta: { request_id: 'abc' },
      },
    });
    await expect(listLearners()).rejects.toThrow(/UNAUTHENTICATED/);
  });

  it('listLearners: parses real ok envelope with request_id meta', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: { items: [account], total: 1, page: 1, limit: 20 },
        meta: { request_id: 'abc' },
      },
    });
    await expect(listLearners({ page: 1, limit: 20 })).resolves.toEqual({
      items: [account],
      total: 1,
      page: 1,
      limit: 20,
    });
  });
});
