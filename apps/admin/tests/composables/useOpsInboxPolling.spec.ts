// @vitest-environment happy-dom

import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchOpsInbox = vi.hoisted(() => vi.fn());
vi.mock('@/api/opsInbox', () => ({ fetchOpsInbox }));

import { useOpsInboxPolling } from '@/composables/useOpsInboxPolling';

describe('useOpsInboxPolling', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    vi.useFakeTimers();
    fetchOpsInbox.mockResolvedValue({
      items: [],
      total: 0,
      page: 1,
      limit: 20,
      counts_by_source: {},
    });
  });

  it('clears the polling interval on unmount', async () => {
    const clearInterval = vi.spyOn(window, 'clearInterval');
    const wrapper = mount(
      defineComponent({
        setup() {
          useOpsInboxPolling(() => ({
            state: 'open',
            sort_by: 'weight',
            sort_dir: 'desc',
            page: 1,
            limit: 20,
          }));
          return () => h('div');
        },
      }),
    );
    await flushPromises();
    wrapper.unmount();
    expect(clearInterval).toHaveBeenCalledTimes(1);
  });
});
