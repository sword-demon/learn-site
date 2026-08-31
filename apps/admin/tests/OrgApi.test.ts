import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockHttp = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  patch: vi.fn(),
  put: vi.fn(),
  delete: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp }));

import { listPosts, setStaffOverrides } from '@/api/org';

const post = {
  id: 4,
  department_id: 2,
  department_name: '内容部',
  name: '课程运营',
  status: 'enabled',
  role_ids: [3, 5],
  created_at: '2026-08-25 12:00:00',
  updated_at: '2026-08-25 12:00:00',
};

describe('organization API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('unwraps and validates an organization response envelope', async () => {
    mockHttp.get.mockResolvedValueOnce({
      data: { ok: true, data: { items: [post] }, error: null },
    });

    await expect(listPosts()).resolves.toEqual({ items: [post] });
  });

  it('rejects malformed organization payloads at the API boundary', async () => {
    const malformed = { ...post, role_ids: undefined };
    mockHttp.get.mockResolvedValueOnce({
      data: { ok: true, data: { items: [malformed] }, error: null },
    });

    await expect(listPosts()).rejects.toThrow();
  });

  it('sends and validates the staff override replacement payload', async () => {
    mockHttp.put.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          overrides: [{ effect: 'deny', code: 'order.view', permission_id: 21 }],
        },
        error: null,
      },
    });

    await expect(
      setStaffOverrides(12, {
        entries: [{ code: 'order.view', effect: 'deny' }],
      }),
    ).resolves.toEqual({
      overrides: [{ effect: 'deny', code: 'order.view', permission_id: 21 }],
    });
    expect(mockHttp.put).toHaveBeenCalledWith('/staff/12/overrides', {
      entries: [{ code: 'order.view', effect: 'deny' }],
    });
  });
});
