// @vitest-environment happy-dom

import { createPinia, setActivePinia } from 'pinia';
import { defineComponent, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { useLoginFamilyStore } from '@/api/login';
import { useDailyCheckinPrompt } from '@/composables/useDailyCheckinPrompt';

const checkinsApi = vi.hoisted(() => ({
  fetchTodayCheckinStatus: vi.fn(),
}));
const authApi = vi.hoisted(() => ({
  clearTokens: vi.fn(),
  hasTokens: vi.fn(() => false),
  setTokens: vi.fn(),
  http: { post: vi.fn().mockResolvedValue({}) },
}));

vi.mock('@/api/checkins', () => checkinsApi);
vi.mock('@/api/http', () => authApi);

function mountPrompt() {
  let prompt: ReturnType<typeof useDailyCheckinPrompt> | undefined;
  const wrapper = mount(
    defineComponent({
      setup() {
        prompt = useDailyCheckinPrompt();
        return () => null;
      },
    }),
  );
  if (!prompt) throw new Error('prompt was not initialized');
  return { prompt, wrapper };
}

describe('useDailyCheckinPrompt', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    sessionStorage.clear();
    checkinsApi.fetchTodayCheckinStatus.mockReset();
    checkinsApi.fetchTodayCheckinStatus.mockResolvedValue({
      server_date: '2026-08-30',
      checked_in: false,
      record: null,
    });
  });

  it('does not show the prompt after today has been checked in', async () => {
    checkinsApi.fetchTodayCheckinStatus.mockResolvedValueOnce({
      server_date: '2026-08-30',
      checked_in: true,
      record: {
        id: 1,
        checkin_date: '2026-08-30',
        plan_html: '<p>已完成</p>',
        checked_in_at: '2026-08-30T08:00:00+08:00',
      },
    });
    const session = useLoginFamilyStore();
    session.signIn({
      access_token: 'learner-a',
      refresh_token: 'refresh-a',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });

    const { prompt } = mountPrompt();

    await vi.waitFor(() => expect(prompt.checkedInToday.value).toBe(true));
    expect(prompt.dialogVisible.value).toBe(false);
  });

  it('does not carry one learner dismissal into the next login', async () => {
    const session = useLoginFamilyStore();
    session.signIn({
      access_token: 'learner-a',
      refresh_token: 'refresh-a',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });
    const { prompt } = mountPrompt();
    await vi.waitFor(() => expect(prompt.dialogVisible.value).toBe(true));
    prompt.dismissForSession();
    expect(prompt.dialogVisible.value).toBe(false);

    await session.signOut();
    session.signIn({
      access_token: 'learner-b',
      refresh_token: 'refresh-b',
      access_expires_in: 900,
      refresh_expires_in: 604800,
    });
    await vi.waitFor(() => expect(checkinsApi.fetchTodayCheckinStatus).toHaveBeenCalledTimes(2));
    expect(prompt.dialogVisible.value).toBe(true);
  });

  it('allows success hooks to unsubscribe', async () => {
    const { prompt } = mountPrompt();
    const hook = vi.fn();
    const unsubscribe = prompt.afterSuccess(hook);

    expect(unsubscribe).toEqual(expect.any(Function));
    unsubscribe();
    prompt.onCheckinSuccess();
    await nextTick();
    expect(hook).not.toHaveBeenCalled();
  });
});
