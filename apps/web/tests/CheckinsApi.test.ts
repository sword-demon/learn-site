import axios from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createCheckin } from '@/api/checkins';

const httpApi = vi.hoisted(() => ({
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http: httpApi }));

describe('learner check-in API', () => {
  beforeEach(() => {
    httpApi.post.mockReset();
  });

  it('exposes the server business error code for duplicate check-ins', async () => {
    httpApi.post.mockRejectedValue(
      new axios.AxiosError(
        'Request failed with status code 409',
        'ERR_BAD_REQUEST',
        undefined,
        undefined,
        {
          data: {
            ok: false,
            data: null,
            error: { code: 'ALREADY_CHECKED_IN', message: 'ALREADY_CHECKED_IN' },
          },
          status: 409,
          statusText: 'Conflict',
          headers: {},
          config: { headers: new axios.AxiosHeaders() },
        },
      ),
    );

    await expect(createCheckin('<p>重复</p>')).rejects.toMatchObject({
      code: 'ALREADY_CHECKED_IN',
      message: 'ALREADY_CHECKED_IN',
    });
  });
});
