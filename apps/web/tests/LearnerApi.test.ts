import type { AxiosAdapter, AxiosResponse } from 'axios';
import { afterEach, describe, expect, it } from 'vitest';
import type { LearnerProfileDTO } from '@learn-site/contracts';

import { deleteLearnerAvatar, uploadLearnerAvatar } from '@/api/learner';
import { clearTokens, http, setTokens } from '@/api/http';

const profile: LearnerProfileDTO = {
  account_id: 7,
  phone: '13800138000',
  nickname: '林间学员',
  avatar_url: '/api/media/avatars/2026/09/aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa.webp',
  show_on_course: false,
  status: 'active',
  created_at: '2026-08-30 10:00:00',
};

describe('learner avatar API', () => {
  const originalAdapter = http.defaults.adapter;

  afterEach(() => {
    if (originalAdapter === undefined) {
      delete http.defaults.adapter;
    } else {
      http.defaults.adapter = originalAdapter;
    }
    clearTokens();
  });

  it('posts the avatar file as multipart and returns the profile', async () => {
    let requestedUrl = '';
    let contentType: string | undefined;
    let body: unknown;
    const adapter: AxiosAdapter = async (config) => {
      requestedUrl = config.url ?? '';
      contentType = config.headers.get('Content-Type')?.toString();
      body = config.data;
      return {
        data: { ok: true, data: profile },
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      } satisfies AxiosResponse;
    };
    http.defaults.adapter = adapter;
    setTokens({
      access_token: 'avatar-access',
      refresh_token: 'avatar-refresh',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });

    const file = new File(['avatar'], 'avatar.webp', { type: 'image/webp' });
    const result = await uploadLearnerAvatar(file);

    expect(requestedUrl).toBe('/me/avatar');
    expect(contentType).toContain('multipart/form-data');
    expect(body).toBeInstanceOf(FormData);
    expect(result.avatar_url).toBe(profile.avatar_url);
  });

  it('deletes the current avatar and returns a cleared profile', async () => {
    let requestedUrl = '';
    let method = '';
    const adapter: AxiosAdapter = async (config) => {
      requestedUrl = config.url ?? '';
      method = (config.method ?? '').toLowerCase();
      return {
        data: { ok: true, data: { ...profile, avatar_url: null } },
        status: 200,
        statusText: 'OK',
        headers: {},
        config,
      } satisfies AxiosResponse;
    };
    http.defaults.adapter = adapter;
    setTokens({
      access_token: 'avatar-access',
      refresh_token: 'avatar-refresh',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });

    const result = await deleteLearnerAvatar();

    expect(requestedUrl).toBe('/me/avatar');
    expect(method).toBe('delete');
    expect(result.avatar_url).toBeNull();
  });
});
