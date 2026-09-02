// @vitest-environment happy-dom

import axios, { type AxiosError } from 'axios';
import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import ActivationCodeRedeemForm from '@/components/ActivationCodeRedeemForm.vue';

const httpApi = vi.hoisted(() => ({
  post: vi.fn(),
}));

vi.mock('@/api/http', () => ({ http: httpApi }));

const RouterLinkStub = {
  props: { to: { type: [String, Object], required: true } },
  template: '<a :href="String(to)"><slot /></a>',
};

function mountForm() {
  return mount(ActivationCodeRedeemForm, {
    global: { stubs: { RouterLink: RouterLinkStub } },
  });
}

function apiError(code: string, message = code, status = 409): AxiosError {
  return new axios.AxiosError(
    `Request failed with status code ${status}`,
    'ERR_BAD_REQUEST',
    undefined,
    undefined,
    {
      data: {
        ok: false,
        data: null,
        error: { code, message },
      },
      status,
      statusText: status === 429 ? 'Too Many Requests' : 'Conflict',
      headers: {},
      config: { headers: new axios.AxiosHeaders() },
    },
  );
}

beforeEach(() => {
  httpApi.post.mockReset();
});

describe('ActivationCodeRedeemForm', () => {
  it('redeems a code and exposes the granted course through a link and event', async () => {
    const result = {
      granted: true as const,
      course_id: 42,
      course_title: 'Vue 组件设计',
      source: 'activation_code' as const,
    };
    httpApi.post.mockResolvedValue({
      data: { ok: true, data: result, error: null },
    });
    const wrapper = mountForm();

    await wrapper.get('input').setValue('  AB3D-EFGH-JKMN-PQRS  ');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(httpApi.post).toHaveBeenCalledWith('/activation-codes/redeem', {
      code: 'AB3D-EFGH-JKMN-PQRS',
    });
    expect(wrapper.emitted('success')?.[0]).toEqual([result]);
    expect(wrapper.get('[role="status"]').text()).toContain('Vue 组件设计');
    expect(wrapper.get('a').attributes('href')).toBe('/courses/42');
  });

  it('shows a useful Chinese message for the domain error returned by the API', async () => {
    httpApi.post.mockRejectedValue(apiError('CONFLICT', 'ACTIVATION_CODE_EXPIRED'));
    const wrapper = mountForm();

    await wrapper.get('input').setValue('AB3D-EFGH-JKMN-PQRS');
    await wrapper.get('form').trigger('submit');
    await flushPromises();

    expect(wrapper.get('[role="alert"]').text()).toBe('该激活码已过期。');
  });

  it('ignores repeat submissions while a redemption is in flight', async () => {
    let resolveRequest!: (value: unknown) => void;
    httpApi.post.mockReturnValue(
      new Promise((resolve) => {
        resolveRequest = resolve;
      }),
    );
    const wrapper = mountForm();

    await wrapper.get('input').setValue('AB3D-EFGH-JKMN-PQRS');
    await wrapper.get('form').trigger('submit');
    await wrapper.get('form').trigger('submit');

    expect(httpApi.post).toHaveBeenCalledTimes(1);
    expect(wrapper.getComponent({ name: 'ElInput' }).props('disabled')).toBe(true);
    expect(wrapper.getComponent({ name: 'ElButton' }).props('loading')).toBe(true);

    resolveRequest({
      data: {
        ok: true,
        data: {
          granted: true,
          course_id: 42,
          course_title: 'Vue 组件设计',
          source: 'activation_code',
        },
        error: null,
      },
    });
    await flushPromises();
  });
});
