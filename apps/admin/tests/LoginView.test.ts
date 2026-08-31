// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const httpApi = vi.hoisted(() => ({
  get: vi.fn(),
  post: vi.fn(),
  setTokens: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  push: vi.fn(),
  route: undefined as { query: Record<string, string> } | undefined,
}));
const messageApi = vi.hoisted(() => ({ error: vi.fn() }));

vi.mock('@/api/http', () => ({
  http: { get: httpApi.get, post: httpApi.post },
  setTokens: httpApi.setTokens,
}));
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ push: routerApi.push }),
}));
vi.mock('element-plus', async (importOriginal) => ({
  ...(await importOriginal<typeof import('element-plus')>()),
  ElMessage: messageApi,
}));

import LoginView from '@/views/auth/LoginView.vue';

describe('LoginView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route = reactive({ query: {} });
    httpApi.get.mockResolvedValue({
      data: {
        ok: true,
        data: {
          captcha_id: 'captcha-123',
          image: 'data:image/png;base64,AA==',
          ttl_seconds: 120,
        },
      },
    });
    httpApi.post.mockResolvedValue({
      data: {
        ok: true,
        data: {
          access_token: 'access-token',
          access_expires_in: 900,
          refresh_token: 'refresh-token',
          refresh_expires_in: 604800,
        },
      },
    });
  });

  it('submits the captcha_id returned by the captcha challenge', async () => {
    const wrapper = mount(LoginView);
    await flushPromises();

    await wrapper.get('input[placeholder="3–64 位管理员账号"]').setValue('admin');
    await wrapper.get('input[placeholder="8–72 位"]').setValue('change-me-now');
    await wrapper.get('input[placeholder="图中字符"]').setValue('x4rv');
    await wrapper.get('button[type="submit"]').trigger('click');
    await flushPromises();

    expect(httpApi.post).toHaveBeenCalledWith('/auth/login', {
      account: 'admin',
      password: 'change-me-now',
      captcha_id: 'captcha-123',
      captcha_answer: 'x4rv',
    });
  });

  it('routes must-change accounts to first-password instead of the requested page', async () => {
    httpApi.post.mockResolvedValueOnce({
      data: {
        ok: true,
        data: {
          access_token: 'access-token',
          access_expires_in: 900,
          refresh_token: 'refresh-token',
          refresh_expires_in: 604800,
          must_change_password: true,
          permission_codes: ['*'],
        },
      },
    });
    routerApi.route = reactive({ query: { next: '/courses' } });

    const wrapper = mount(LoginView);
    await flushPromises();
    await wrapper.get('input[placeholder="3–64 位管理员账号"]').setValue('admin');
    await wrapper.get('input[placeholder="8–72 位"]').setValue('change-me-now');
    await wrapper.get('input[placeholder="图中字符"]').setValue('x4rv');
    await wrapper.get('button[type="submit"]').trigger('click');
    await flushPromises();

    expect(routerApi.push).toHaveBeenCalledWith({
      name: 'first-password',
      query: { next: '/courses' },
    });
  });

  it('rejects a malformed token envelope instead of creating a broken session', async () => {
    httpApi.post.mockResolvedValueOnce({
      data: { ok: true, data: { access_token: 'incomplete' } },
    });

    const wrapper = mount(LoginView);
    await flushPromises();
    await wrapper.get('input[placeholder="3–64 位管理员账号"]').setValue('admin');
    await wrapper.get('input[placeholder="8–72 位"]').setValue('change-me-now');
    await wrapper.get('input[placeholder="图中字符"]').setValue('x4rv');
    await wrapper.get('button[type="submit"]').trigger('click');
    await flushPromises();

    expect(httpApi.setTokens).not.toHaveBeenCalled();
    expect(routerApi.push).not.toHaveBeenCalled();
    expect(messageApi.error).toHaveBeenCalledWith('登录失败，请稍后重试');
  });
});
