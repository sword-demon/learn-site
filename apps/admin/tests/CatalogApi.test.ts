import { beforeEach, describe, expect, it, vi } from 'vitest';

const mockHttp = vi.hoisted(() => ({
  delete: vi.fn(),
  get: vi.fn(),
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ default: mockHttp }));

import {
  deleteCourse,
  listCategoriesFlat,
  listCategoryTree,
  uploadCourseCover,
} from '@/api/catalog';

const root = {
  id: 1,
  parent_id: 0,
  name: '编程基础',
  path: '/1',
  depth: 1,
  sort: 0,
  status: 'enabled' as const,
  created_at: '2026-08-26 10:00:00',
  updated_at: '2026-08-26 10:00:00',
};

describe('catalog API boundary', () => {
  beforeEach(() => {
    vi.clearAllMocks();
  });

  it('unwraps the category index and returns full nodes for tree consumers', async () => {
    const child = {
      ...root,
      id: 2,
      parent_id: 1,
      name: 'Web 前端',
      path: '/1/2',
      depth: 2,
    };
    mockHttp.get.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          tree: [
            {
              id: root.id,
              parent_id: root.parent_id,
              name: root.name,
              children: [
                { id: child.id, parent_id: child.parent_id, name: child.name, children: [] },
              ],
            },
          ],
          flat: [root, child],
        },
        error: null,
      },
    });

    await expect(listCategoryTree()).resolves.toEqual([
      {
        ...root,
        children: [{ ...child, children: [] }],
      },
    ]);
  });

  it('unwraps the standard API envelope for the flat category list', async () => {
    const list = { items: [root], total: 1, page: 1, limit: 20 };
    mockHttp.get.mockResolvedValueOnce({
      data: { ok: true, data: list },
    });

    await expect(listCategoriesFlat({ page: 1, limit: 20 })).resolves.toEqual(list);
    expect(mockHttp.get).toHaveBeenCalledWith('/categories/flat', {
      params: { page: 1, limit: 20 },
    });
  });

  it('uploads a course cover and unwraps the standard API envelope', async () => {
    const file = new File(['image'], 'cover.webp', { type: 'image/webp' });
    mockHttp.post.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          key: 'covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
          url: '/api/media/covers/2026/08/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
          mime_type: 'image/webp',
          size_bytes: 5,
        },
        error: null,
      },
    });

    await expect(uploadCourseCover({ file })).resolves.toMatchObject({
      mime_type: 'image/webp',
      size_bytes: 5,
    });
    const call = mockHttp.post.mock.calls[0];
    expect(call).toBeDefined();
    const path = call![0];
    const body = call![1];
    expect(path).toBe('/course-covers');
    expect(body.get('file')).toBe(file);
  });

  it('validates and returns the course deletion result', async () => {
    mockHttp.delete.mockResolvedValueOnce({
      data: { ok: true, data: { deleted: true } },
    });

    await expect(deleteCourse(12)).resolves.toEqual({ deleted: true });
    expect(mockHttp.delete).toHaveBeenCalledWith('/courses/12');
  });
});
