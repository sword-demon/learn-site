// @vitest-environment happy-dom

import { flushPromises, mount } from '@vue/test-utils';
import { reactive } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const httpApi = vi.hoisted(() => ({
  post: vi.fn(),
}));
const authApi = vi.hoisted(() => ({
  clearTokens: vi.fn(),
}));
const routerApi = vi.hoisted(() => ({
  replace: vi.fn(),
  route: undefined as { query: Record<string, string> } | undefined,
}));
const messageApi = vi.hoisted(() => ({ error: vi.fn(), success: vi.fn() }));

vi.mock('@/api/http', () => ({
  http: { post: httpApi.post },
  clearTokens: authApi.clearTokens,
}));
vi.mock('vue-router', () => ({
  useRoute: () => routerApi.route,
  useRouter: () => ({ replace: routerApi.replace }),
}));
vi.mock('element-plus', async (importOriginal) => ({
  ...(await importOriginal<typeof import('element-plus')>()),
  ElMessage: messageApi,
}));

import FirstPasswordView from '@/views/auth/FirstPasswordView.vue';

describe('FirstPasswordView', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    routerApi.route = reactive({ query: { next: '/courses' } });
    httpApi.post.mockResolvedValue({
      data: { ok: true, data: { changed: true } },
    });
  });

  it('changes the initial password and returns to login for a fresh session', async () => {
    const wrapper = mount(FirstPasswordView);
    await wrapper.get('input[autocomplete="current-password"]').setValue('change-me-now');
    await wrapper.get('input[autocomplete="new-password"]').setValue('new-password');
    await wrapper.get('input[autocomplete="new-password-confirm"]').setValue('new-password');
    await wrapper.get('button[type="submit"]').trigger('click');
    await flushPromises();

    expect(httpApi.post).toHaveBeenCalledWith('/auth/password/first', {
      current_password: 'change-me-now',
      new_password: 'new-password',
    });
    expect(authApi.clearTokens).toHaveBeenCalledOnce();
    expect(routerApi.replace).toHaveBeenCalledWith({
      name: 'login',
      query: { next: '/courses', reason: 'password_changed' },
    });
  });
});
