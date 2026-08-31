import type { AxiosAdapter, AxiosResponse } from 'axios';
import { afterEach, describe, expect, it } from 'vitest';

import * as learnerApi from '@/api/learner';
import { clearTokens, http, setTokens } from '@/api/http';

describe('protected media API', () => {
  const originalAdapter = http.defaults.adapter;

  afterEach(() => {
    if (originalAdapter === undefined) {
      delete http.defaults.adapter;
    } else {
      http.defaults.adapter = originalAdapter;
    }
    clearTokens();
  });

  it('downloads the media API URL with the current bearer token', async () => {
    let requestedUrl = '';
    let requestedBaseUrl: string | undefined;
    let authorization: string | undefined;
    const adapter: AxiosAdapter = async (config) => {
      requestedUrl = config.url ?? '';
      requestedBaseUrl = config.baseURL;
      authorization = config.headers.get('Authorization')?.toString();
      return {
        data: new Blob(['video']),
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      } satisfies AxiosResponse<Blob>;
    };
    http.defaults.adapter = adapter;
    setTokens({
      access_token: 'media-access',
      refresh_token: 'media-refresh',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });

    const api = learnerApi as typeof learnerApi & {
      fetchMediaObjectUrl?: (url: string) => Promise<string>;
    };
    expect(typeof api.fetchMediaObjectUrl).toBe('function');
    const objectUrl = await api.fetchMediaObjectUrl?.('/api/media/assets/12');

    expect(objectUrl).toMatch(/^blob:/);
    expect(requestedUrl).toBe('/api/media/assets/12');
    expect(requestedBaseUrl).toBe('');
    expect(authorization).toBe('Bearer media-access');
    if (objectUrl) URL.revokeObjectURL(objectUrl);
  });
});
